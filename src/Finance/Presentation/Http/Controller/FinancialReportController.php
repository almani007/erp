<?php

declare(strict_types=1);

namespace Finance\Presentation\Http\Controller;

use Finance\Application\GenerateBalanceSheet;
use Finance\Application\GenerateProfitLoss;
use Finance\Application\GenerateTrialBalance;
use Finance\Infrastructure\Persistence\Eloquent\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Shared\Infrastructure\Validation\TenantRule;

/**
 * Every report is constrained to a company that was validated against the
 * caller's tenant, so the raw ledger queries behind them stay tenant-safe.
 */
final class FinancialReportController
{
    public function trialBalance(Request $request, GenerateTrialBalance $report): JsonResponse
    {
        $q = $this->filters($request, ['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return new JsonResponse(['data' => $report->execute($q['company_id'], $q['as_of'] ?? now()->toDateString(), $q['branch_id'] ?? null)]);
    }

    public function profitLoss(Request $request, GenerateProfitLoss $report): JsonResponse
    {
        $q = $this->filters($request, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return new JsonResponse(['data' => $report->execute($q['company_id'], $q['from'], $q['to'], $q['branch_id'] ?? null)]);
    }

    public function balanceSheet(Request $request, GenerateBalanceSheet $report): JsonResponse
    {
        $q = $this->filters($request, ['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return new JsonResponse(['data' => $report->execute($q['company_id'], $q['as_of'] ?? now()->toDateString(), $q['branch_id'] ?? null)]);
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $q = $this->filters($request, [
            'account_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $account = Account::query()->where('company_id', $q['company_id'])->findOrFail($q['account_id']);

        $base = fn () => DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $q['company_id'])
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('jl.account_id', $account->id);

        $opening = $base()->where('je.posting_date', '<', $q['from'])
            ->selectRaw('COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS balance')->value('balance');

        $lines = $base()->whereBetween('je.posting_date', [$q['from'], $q['to']])
            ->orderBy('je.posting_date')->orderBy('jl.id')
            ->limit(5000)
            ->get(['je.id as journal_entry_id', 'je.journal_no', 'je.posting_date', 'je.description', 'je.source_type', 'jl.debit', 'jl.credit']);

        return new JsonResponse(['data' => [
            'account' => $account->only(['id', 'code', 'name', 'account_type']),
            'opening_balance' => (string) $opening,
            'lines' => $lines,
        ]]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $extra): array
    {
        return $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
        ] + $extra);
    }
}
