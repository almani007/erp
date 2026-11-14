<?php

declare(strict_types=1);

namespace Finance\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Support\Decimal;

final class GenerateBalanceSheet
{
    /**
     * @return array{assets: Collection, liabilities: Collection, equity: Collection, total_assets: string, total_liabilities: string, total_equity: string, is_balanced: bool}
     */
    public function execute(string $companyId, string $asOfDate, ?string $branchId = null): array
    {
        $baseQuery = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.account_id', '=', 'coa.id')
            ->where('je.company_id', $companyId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.posting_date', '<=', $asOfDate);

        if ($branchId) {
            $baseQuery->where('jl.branch_id', $branchId);
        }

        $assets = (clone $baseQuery)
            ->whereIn('coa.account_type', ['asset', 'contra_asset'])
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

        $liabilities = (clone $baseQuery)
            ->whereIn('coa.account_type', ['liability', 'contra_liability'])
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

        $equity = (clone $baseQuery)
            ->where('coa.account_type', 'equity')
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

        // Calculate retained earnings (revenue - expenses for all periods up to asOfDate)
        $retainedEarnings = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.account_id', '=', 'coa.id')
            ->where('je.company_id', $companyId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.posting_date', '<=', $asOfDate)
            ->whereIn('coa.account_type', ['revenue', 'contra_revenue', 'expense', 'contra_expense'])
            ->when($branchId, fn ($q) => $q->where('jl.branch_id', $branchId))
            ->select(DB::raw('SUM(jl.credit) - SUM(jl.debit) as net_income'))
            ->value('net_income') ?? '0';

        $totalAssets = Decimal::sum($assets, 'balance');
        $totalLiabilities = Decimal::sum($liabilities, 'balance');
        $totalEquity = bcadd(Decimal::sum($equity, 'balance'), (string) $retainedEarnings, 4);

        $liabilitiesAndEquity = bcadd($totalLiabilities, $totalEquity, 4);
        $isBalanced = bccomp($totalAssets, $liabilitiesAndEquity, 4) === 0;

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'retained_earnings' => (string) $retainedEarnings,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'is_balanced' => $isBalanced,
        ];
    }
}
