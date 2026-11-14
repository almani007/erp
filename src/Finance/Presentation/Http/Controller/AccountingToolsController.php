<?php

declare(strict_types=1);

namespace Finance\Presentation\Http\Controller;

use Finance\Application\AdvancedReports;
use Finance\Application\CloseFinancialYear;
use Finance\Application\RunRecurringJournals;
use Finance\Infrastructure\Persistence\Eloquent\Account;
use Finance\Infrastructure\Persistence\Eloquent\Budget;
use Finance\Infrastructure\Persistence\Eloquent\RecurringJournal;
use Finance\Infrastructure\Persistence\Eloquent\YearEndClosing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;
use Shared\Infrastructure\Validation\TenantRule;

/** Budgets, recurring journals, year-end close and management reports. */
final class AccountingToolsController
{
    private function company(Request $request): string
    {
        $id = $request->validate(['company_id' => ['nullable', 'uuid', TenantRule::exists('companies')]])['company_id'] ?? null;

        return $id ?? (string) Company::query()->orderBy('created_at')->value('id');
    }

    /** @return array{from: string, to: string} */
    private function range(Request $request): array
    {
        return $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
    }

    // ── Reports ──────────────────────────────────────────────────

    public function profitLossMatrix(Request $request, AdvancedReports $reports): JsonResponse
    {
        $r = $this->range($request);
        $by = $request->validate(['by' => ['nullable', 'in:month,branch']])['by'] ?? 'month';

        return new JsonResponse(['data' => $reports->profitLossMatrix($this->company($request), $r['from'], $r['to'], $by)]);
    }

    public function cashFlow(Request $request, AdvancedReports $reports): JsonResponse
    {
        $r = $this->range($request);

        return new JsonResponse(['data' => $reports->cashFlow($this->company($request), $r['from'], $r['to'])]);
    }

    public function salesTax(Request $request, AdvancedReports $reports): JsonResponse
    {
        $r = $this->range($request);

        return new JsonResponse(['data' => $reports->salesTax($this->company($request), $r['from'], $r['to'])]);
    }

    public function budgetVsActual(Request $request, string $budgetId, AdvancedReports $reports): JsonResponse
    {
        $r = $this->range($request);
        $branch = $request->validate(['branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')]])['branch_id'] ?? null;

        return new JsonResponse(['data' => $reports->budgetVsActual(Budget::query()->findOrFail($budgetId), $r['from'], $r['to'], $branch)]);
    }

    // ── Budgets ──────────────────────────────────────────────────

    public function budgets(): JsonResponse
    {
        return new JsonResponse(['data' => Budget::query()->withCount('lines')->orderByDesc('start_date')->get()]);
    }

    public function budget(string $id): JsonResponse
    {
        return new JsonResponse(['data' => Budget::query()->with('lines')->findOrFail($id)]);
    }

    /** Create or replace a budget with all its lines. */
    public function saveBudget(Request $request, AuditTrail $audit, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'lines' => ['present', 'array', 'max:1000'],
            'lines.*.account_id' => ['required', 'uuid', TenantRule::exists('chart_of_accounts')],
            'lines.*.branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
            'lines.*.amounts' => ['required', 'array', 'size:12'],
            'lines.*.amounts.*' => ['nullable', 'numeric'],
        ]);
        $companyId = $this->company($request);

        $budget = DB::transaction(function () use ($data, $companyId, $request, $id): Budget {
            $budget = $id === null
                ? Budget::query()->create(['company_id' => $companyId, 'name' => $data['name'], 'start_date' => substr($data['start_date'], 0, 8).'01', 'status' => 'active', 'created_by' => $request->user()->id])
                : tap(Budget::query()->lockForUpdate()->findOrFail($id))->update(['name' => $data['name'], 'start_date' => substr($data['start_date'], 0, 8).'01']);
            $budget->lines()->delete();
            foreach ($data['lines'] as $line) {
                $budget->lines()->create([
                    'account_id' => $line['account_id'], 'branch_id' => $line['branch_id'] ?? null,
                    'amounts' => array_map(static fn ($v): string => Decimal::round((string) ($v ?? '0'), 2), $line['amounts']),
                ]);
            }

            return $budget;
        });
        $audit->record('finance.budget.saved', 'budget', $budget->id, null, ['name' => $budget->name, 'lines' => count($data['lines'])]);

        return new JsonResponse(['data' => $budget->load('lines')], $id === null ? 201 : 200);
    }

    // ── Recurring journals ───────────────────────────────────────

    public function recurring(): JsonResponse
    {
        return new JsonResponse(['data' => RecurringJournal::query()->orderBy('next_run_date')->get()]);
    }

    public function saveRecurring(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'frequency' => ['required', 'in:weekly,monthly,quarterly,yearly'],
            'next_run_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:next_run_date'],
            'status' => ['sometimes', 'in:active,paused'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'uuid', TenantRule::exists('chart_of_accounts')],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $debit = $credit = '0';
        foreach ($data['lines'] as $i => $l) {
            $d = Decimal::round((string) ($l['debit'] ?? '0'), 2);
            $c = Decimal::round((string) ($l['credit'] ?? '0'), 2);
            if (Decimal::isPositive($d) === Decimal::isPositive($c)) {
                throw new \DomainException('Line '.($i + 1).': enter a debit or a credit, not both.');
            }
            $data['lines'][$i]['debit'] = $d;
            $data['lines'][$i]['credit'] = $c;
            $debit = Decimal::add($debit, $d, 2);
            $credit = Decimal::add($credit, $c, 2);
            Account::query()->where('company_id', $this->company($request))->findOrFail($l['account_id']);
        }
        if (Decimal::cmp($debit, $credit) !== 0) {
            throw new \DomainException("Debits ({$debit}) and credits ({$credit}) must be equal.");
        }

        $template = $id === null
            ? RecurringJournal::query()->create($data + ['company_id' => $this->company($request), 'status' => 'active', 'created_by' => $request->user()->id])
            : tap(RecurringJournal::query()->findOrFail($id))->update($data);

        return new JsonResponse(['data' => $template], $id === null ? 201 : 200);
    }

    public function runRecurring(Request $request, RunRecurringJournals $runner, TenantContext $tenant): JsonResponse
    {
        $only = $request->validate(['id' => ['nullable', 'uuid']])['id'] ?? null;

        return new JsonResponse(['data' => $runner->runDue($tenant->requireId(), null, $only)]);
    }

    // ── Year-end close ───────────────────────────────────────────

    public function closings(): JsonResponse
    {
        return new JsonResponse(['data' => YearEndClosing::query()->orderByDesc('year_end')->get()]);
    }

    public function closeYear(Request $request, CloseFinancialYear $action): JsonResponse
    {
        $r = $request->validate([
            'year_start' => ['required', 'date_format:Y-m-d'],
            'year_end' => ['required', 'date_format:Y-m-d', 'after:year_start', 'before_or_equal:today'],
        ]);

        return new JsonResponse(['data' => $action->close($this->company($request), $r['year_start'], $r['year_end'], $request->user()->id)], 201);
    }

    public function reopenYear(Request $request, string $id, CloseFinancialYear $action): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];

        return new JsonResponse(['data' => $action->reopen($id, $reason)]);
    }
}
