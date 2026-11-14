<?php

declare(strict_types=1);

namespace Finance\Application;

use Finance\Infrastructure\Persistence\Eloquent\JournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class GenerateTrialBalance
{
    /**
     * @return Collection<int, object{account_id: string, account_code: string, account_name: string, account_type: string, debit_balance: string, credit_balance: string}>
     */
    public function execute(string $companyId, string $asOfDate, ?string $branchId = null): Collection
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.account_id', '=', 'coa.id')
            ->where('je.company_id', $companyId)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.posting_date', '<=', $asOfDate)
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.account_type')
            ->select([
                'coa.id as account_id',
                'coa.code as account_code',
                'coa.name as account_name',
                'coa.account_type',
                DB::raw('SUM(jl.debit) as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit'),
                DB::raw('CASE WHEN SUM(jl.debit) > SUM(jl.credit) THEN SUM(jl.debit) - SUM(jl.credit) ELSE 0 END as debit_balance'),
                DB::raw('CASE WHEN SUM(jl.credit) > SUM(jl.debit) THEN SUM(jl.credit) - SUM(jl.debit) ELSE 0 END as credit_balance'),
            ])
            ->orderBy('coa.code');

        if ($branchId) {
            $query->where('jl.branch_id', $branchId);
        }

        return $query->get();
    }
}
