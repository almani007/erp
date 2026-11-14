<?php

declare(strict_types=1);

namespace Finance\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Support\Decimal;

final class GenerateProfitLoss
{
    /**
     * @return array{revenue: Collection, expenses: Collection, net_income: string}
     */
    public function execute(string $companyId, string $fromDate, string $toDate, ?string $branchId = null): array
    {
        $baseQuery = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.account_id', '=', 'coa.id')
            ->where('je.company_id', $companyId)
            ->whereIn('je.status', ['posted', 'reversed'])
            // A closed year still shows its results: the closing entry only moves them to retained earnings.
            ->where('je.source_type', '!=', 'year_end_close')
            ->whereBetween('je.posting_date', [$fromDate, $toDate]);

        if ($branchId) {
            $baseQuery->where('jl.branch_id', $branchId);
        }

        $revenue = (clone $baseQuery)
            ->whereIn('coa.account_type', ['revenue', 'contra_revenue'])
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.account_type')
            ->select([
                'coa.id as account_id',
                'coa.code as account_code',
                'coa.name as account_name',
                'coa.account_type',
                DB::raw('SUM(jl.credit) - SUM(jl.debit) as balance'),
            ])
            ->orderBy('coa.code')
            ->get();

        $expenses = (clone $baseQuery)
            ->whereIn('coa.account_type', ['expense', 'contra_expense'])
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.account_type')
            ->select([
                'coa.id as account_id',
                'coa.code as account_code',
                'coa.name as account_name',
                'coa.account_type',
                DB::raw('SUM(jl.debit) - SUM(jl.credit) as balance'),
            ])
            ->orderBy('coa.code')
            ->get();

        $totalRevenue = Decimal::sum($revenue, 'balance');
        $totalExpenses = Decimal::sum($expenses, 'balance');
        $netIncome = bcsub((string) $totalRevenue, (string) $totalExpenses, 4);

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue' => (string) $totalRevenue,
            'total_expenses' => (string) $totalExpenses,
            'net_income' => $netIncome,
        ];
    }
}
