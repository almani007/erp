<?php

declare(strict_types=1);

namespace People\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use People\Infrastructure\Persistence\Eloquent\Employee;
use People\Infrastructure\Persistence\Eloquent\EmployeeAdvance;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/** Salary advance or loan, recovered in installments through payroll. */
final class GiveAdvance
{
    public function __construct(
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
    ) {}

    /** @param  array{date: string, kind: string, amount: string, installment: string|null, paid_from: string, note?: string|null}  $data */
    public function execute(Employee $employee, array $data): EmployeeAdvance
    {
        $amount = Decimal::round($data['amount'], 2);
        $installment = Decimal::round($data['installment'] ?? $amount, 2);
        if (! Decimal::isPositive($amount) || ! Decimal::isPositive($installment)) {
            throw new \DomainException('Amount and installment must be positive.');
        }
        if (Decimal::cmp($installment, $amount) > 0) {
            $installment = $amount;
        }

        return DB::transaction(function () use ($employee, $data, $amount, $installment): EmployeeAdvance {
            $advance = EmployeeAdvance::query()->create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'advance_no' => $this->numbers->next($this->tenant->requireId(), 'employee_advance', $employee->company_id, 'ADV'),
                'date' => $data['date'],
                'kind' => $data['kind'],
                'amount' => $amount,
                'installment' => $installment,
                'paid_from' => $data['paid_from'],
                'status' => 'open',
                'note' => $data['note'] ?? null,
                'created_by' => $this->auth->guard('sanctum')->id(),
            ]);

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $employee->company_id,
                postingDate: $data['date'],
                sourceType: 'employee_advance',
                sourceId: $advance->id,
                description: ucfirst($data['kind'])." {$advance->advance_no} to {$employee->name}",
                lines: [
                    JournalLineDraft::debit($this->accounts->id($employee->company_id, 'employee_advances'), $amount, $employee->branch_id, null, 'employee', $employee->id),
                    JournalLineDraft::credit($this->accounts->id($employee->company_id, $data['paid_from'] === 'bank' ? 'bank' : 'cash'), $amount, $employee->branch_id),
                ],
            ));

            $advance->update(['journal_entry_id' => $journal->id]);
            $this->audit->record('people.advance.given', 'employee_advance', $advance->id, null, ['employee' => $employee->code, 'amount' => $amount]);

            return $advance->load('employee:id,code,name');
        });
    }
}
