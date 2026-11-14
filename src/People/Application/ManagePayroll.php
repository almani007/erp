<?php

declare(strict_types=1);

namespace People\Application;

use Carbon\CarbonImmutable;
use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Identity\Infrastructure\Provider\IdentityServiceProvider;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use People\Infrastructure\Persistence\Eloquent\Employee;
use People\Infrastructure\Persistence\Eloquent\PayrollLine;
use People\Infrastructure\Persistence\Eloquent\PayrollRun;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Monthly payroll: draft (calculated from salaries, attendance and open
 * advances; lines can be adjusted) → approved by someone other than the
 * preparer (salary expense booked, advances recovered) → paid (cash or bank).
 *
 * Approval journal:  Dr Salaries & wages  (gross)
 *                    Cr Employee advances (recovered)
 *                    Cr Payroll deductions payable (tax, EOBI, other)
 *                    Cr Salaries payable  (net)
 * Payment journal:   Dr Salaries payable  Cr Cash / Bank
 */
final class ManagePayroll
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
    ) {}

    public function create(string $companyId, string $period, ?string $branchId): PayrollRun
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period)->startOfMonth();
        $end = $start->endOfMonth();

        return DB::transaction(function () use ($companyId, $period, $branchId, $start, $end): PayrollRun {
            $exists = PayrollRun::query()->where('company_id', $companyId)->where('period', $period)->where('status', '!=', 'cancelled')
                // A company-wide run overlaps every branch run, and the other way round.
                ->when($branchId !== null, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))
                ->exists();
            if ($exists) {
                throw new \DomainException("A payroll for {$period} already exists. Cancel it first to start again.");
            }

            $run = PayrollRun::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'run_no' => $this->numbers->next($this->tenant->requireId(), 'payroll_run', $companyId, 'PAY'),
                'period' => $period,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => 'draft',
                'created_by' => $this->auth->guard('sanctum')->id(),
            ]);

            $employees = Employee::query()->where('company_id', $companyId)
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->where('join_date', '<=', $end->toDateString())
                ->where(fn ($q) => $q->whereNull('leave_date')->orWhere('leave_date', '>=', $start->toDateString()))
                ->where('status', '!=', 'inactive')
                ->orderBy('code')->get();

            if ($employees->isEmpty()) {
                throw new \DomainException('No employees were working in this period.');
            }

            foreach ($employees as $employee) {
                $run->lines()->create($this->calculator->calculate($employee, $start, $end));
            }

            $this->totals($run);
            $this->audit->record('people.payroll.created', 'payroll_run', $run->id, null, ['run_no' => $run->run_no, 'period' => $period, 'employees' => $employees->count()]);

            return $this->present($run);
        });
    }

    /** @param  array<string, mixed>  $overrides */
    public function adjustLine(string $runId, string $lineId, array $overrides, ?string $note): PayrollRun
    {
        return DB::transaction(function () use ($runId, $lineId, $overrides, $note): PayrollRun {
            $run = $this->draft($runId);
            $line = PayrollLine::query()->where('payroll_run_id', $run->id)->findOrFail($lineId);

            // Keep earlier manual figures unless this request changes them.
            $current = [
                'absent_days' => (string) $line->absent_days, 'overtime_hours' => (string) $line->overtime_hours,
                'bonus' => (string) $line->bonus, 'other_deduction' => (string) $line->other_deduction,
                'tax' => (string) $line->tax, 'advance_recovery' => (string) $line->advance_recovery,
            ];
            $values = $this->calculator->calculate(
                Employee::query()->findOrFail($line->employee_id),
                CarbonImmutable::parse($run->period_start), CarbonImmutable::parse($run->period_end),
                array_merge($current, array_filter($overrides, static fn ($v): bool => $v !== null)),
            );
            $line->update($values + ['note' => $note ?? $line->note]);

            $this->totals($run);

            return $this->present($run);
        });
    }

    /** Recalculate every line from the current salaries, attendance and advances. */
    public function recalculate(string $runId): PayrollRun
    {
        return DB::transaction(function () use ($runId): PayrollRun {
            $run = $this->draft($runId);
            foreach ($run->lines as $line) {
                $line->update($this->calculator->calculate(
                    Employee::query()->findOrFail($line->employee_id),
                    CarbonImmutable::parse($run->period_start), CarbonImmutable::parse($run->period_end),
                ));
            }
            $this->totals($run);

            return $this->present($run);
        });
    }

    public function approve(string $runId): PayrollRun
    {
        return DB::transaction(function () use ($runId): PayrollRun {
            $run = $this->draft($runId);
            $user = $this->auth->guard('sanctum')->user();

            if ($run->created_by === $user?->getAuthIdentifier() && ! $user->hasRole(IdentityServiceProvider::OWNER_ROLE)) {
                throw new \DomainException('Payroll must be approved by someone other than the person who prepared it.');
            }
            foreach ($run->lines as $line) {
                if (Decimal::isNegative((string) $line->net)) {
                    $name = Employee::query()->whereKey($line->employee_id)->value('name');
                    throw new \DomainException("Net pay for {$name} is below zero. Reduce the deductions first.");
                }
            }

            $gross = Decimal::sum($run->lines, 'gross', 2);
            $recovered = Decimal::sum($run->lines, 'advance_recovery', 2);
            $withheld = Decimal::add(Decimal::add(Decimal::sum($run->lines, 'fixed_deductions', 2), Decimal::sum($run->lines, 'tax', 2), 2), Decimal::sum($run->lines, 'other_deduction', 2), 2);
            $net = Decimal::sum($run->lines, 'net', 2);
            $company = $run->company_id;

            $lines = [JournalLineDraft::debit($this->accounts->id($company, 'salaries_expense'), $gross, $run->branch_id, "Payroll {$run->period}")];
            if (Decimal::isPositive($recovered)) {
                $lines[] = JournalLineDraft::credit($this->accounts->id($company, 'employee_advances'), $recovered, $run->branch_id, 'Advances recovered');
            }
            if (Decimal::isPositive($withheld)) {
                $lines[] = JournalLineDraft::credit($this->accounts->id($company, 'payroll_deductions_payable'), $withheld, $run->branch_id, 'Tax and deductions withheld');
            }
            if (Decimal::isPositive($net)) {
                $lines[] = JournalLineDraft::credit($this->accounts->id($company, 'salaries_payable'), $net, $run->branch_id, 'Net pay');
            }

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $company,
                postingDate: CarbonImmutable::parse($run->period_end)->toDateString(),
                sourceType: 'payroll_run',
                sourceId: $run->id,
                description: "Payroll {$run->run_no} for {$run->period}",
                lines: $lines,
            ));

            // Recovered amounts reduce the advances now; settled ones close.
            foreach ($run->lines as $line) {
                foreach ($line->breakdown['advances'] ?? [] as $part) {
                    $advance = DB::table('employee_advances')->lockForUpdate()->where('id', $part['advance_id'])->first();
                    $recoveredNow = Decimal::add((string) $advance->recovered, (string) $part['amount'], 2);
                    DB::table('employee_advances')->where('id', $advance->id)->update([
                        'recovered' => $recoveredNow,
                        'status' => Decimal::cmp($recoveredNow, (string) $advance->amount) >= 0 ? 'settled' : 'open',
                        'updated_at' => now(),
                    ]);
                }
            }

            $run->update(['status' => 'approved', 'journal_entry_id' => $journal->id, 'approved_by' => $user?->getAuthIdentifier(), 'approved_at' => now()]);
            $this->audit->record('people.payroll.approved', 'payroll_run', $run->id, null, ['run_no' => $run->run_no, 'gross' => $gross, 'net' => $net]);

            return $this->present($run);
        });
    }

    public function pay(string $runId, string $method, string $date): PayrollRun
    {
        return DB::transaction(function () use ($runId, $method, $date): PayrollRun {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($runId);
            if ($run->status !== 'approved') {
                throw new \DomainException("Payroll {$run->run_no} is {$run->status}; only an approved payroll can be paid.");
            }

            $net = (string) $run->net_total;
            $journalId = null;
            if (Decimal::isPositive($net)) {
                $journalId = $this->postJournal->execute(new JournalDraft(
                    companyId: $run->company_id,
                    postingDate: $date,
                    sourceType: 'payroll_payment',
                    sourceId: $run->id,
                    description: "Salaries paid — {$run->run_no} ({$run->period})",
                    lines: [
                        JournalLineDraft::debit($this->accounts->id($run->company_id, 'salaries_payable'), $net, $run->branch_id),
                        JournalLineDraft::credit($this->accounts->id($run->company_id, $method === 'bank' ? 'bank' : 'cash'), $net, $run->branch_id),
                    ],
                ))->id;
            }

            $run->update(['status' => 'paid', 'payment_journal_entry_id' => $journalId, 'paid_at' => now()]);
            $this->audit->record('people.payroll.paid', 'payroll_run', $run->id, null, ['run_no' => $run->run_no, 'method' => $method]);

            return $this->present($run);
        });
    }

    public function cancel(string $runId): PayrollRun
    {
        return DB::transaction(function () use ($runId): PayrollRun {
            $run = $this->draft($runId);
            $run->update(['status' => 'cancelled']);
            $this->audit->record('people.payroll.cancelled', 'payroll_run', $run->id, null, ['run_no' => $run->run_no]);

            return $this->present($run);
        });
    }

    private function draft(string $runId): PayrollRun
    {
        $run = PayrollRun::query()->lockForUpdate()->findOrFail($runId);
        if ($run->status !== 'draft') {
            throw new \DomainException("Payroll {$run->run_no} is {$run->status} and can no longer be changed.");
        }

        return $run;
    }

    private function totals(PayrollRun $run): void
    {
        $lines = $run->lines()->get();
        $gross = Decimal::sum($lines, 'gross', 2);
        $net = Decimal::sum($lines, 'net', 2);
        $run->update(['gross_total' => $gross, 'net_total' => $net, 'deduction_total' => Decimal::sub($gross, $net, 2)]);
    }

    private function present(PayrollRun $run): PayrollRun
    {
        return $run->refresh()->load(['lines' => fn ($q) => $q->with('employee:id,code,name,designation,department,bank_name,bank_account')]);
    }
}
