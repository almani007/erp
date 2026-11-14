<?php

declare(strict_types=1);

namespace Finance\Application;

use Carbon\CarbonImmutable;
use Finance\Infrastructure\Persistence\Eloquent\Budget;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/** Management reports on top of the ledger. All queries are limited to one validated company. */
final class AdvancedReports
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Profit and loss with one column per month (or per branch).
     *
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function profitLossMatrix(string $companyId, string $from, string $to, string $by): array
    {
        $col = $by === 'branch' ? "COALESCE(l.branch_id::text, '-')" : "to_char(e.posting_date, 'YYYY-MM')";

        $data = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.source_type', '!=', 'year_end_close')
            ->whereBetween('e.posting_date', [$from, $to])->whereIn('a.account_type', ['revenue', 'expense'])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.account_type', DB::raw($col))
            ->selectRaw("a.id, a.code, a.name, a.account_type, {$col} as col, sum(l.credit) - sum(l.debit) as amount")
            ->get();

        if ($by === 'branch') {
            $names = DB::table('branches')->where('tenant_id', $this->tenant->requireId())->pluck('name', 'id');
            $keys = $data->pluck('col')->unique()->sort()->values();
            $columns = $keys->map(fn ($k): array => ['key' => (string) $k, 'label' => $k === '-' ? 'Company-wide' : (string) ($names[$k] ?? $k)])->all();
        } else {
            $columns = [];
            for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->lte(CarbonImmutable::parse($to)); $m = $m->addMonth()) {
                $columns[] = ['key' => $m->format('Y-m'), 'label' => $m->format('M Y')];
            }
        }

        $rows = [];
        foreach ($data->groupBy('id') as $accountRows) {
            $first = $accountRows->first();
            $sign = $first->account_type === 'expense' ? '-1' : '1'; // show expenses as positive figures
            $row = ['account_id' => $first->id, 'code' => $first->code, 'name' => $first->name, 'type' => $first->account_type, 'total' => '0'];
            foreach ($accountRows as $r) {
                $v = Decimal::mul((string) $r->amount, $sign, 2);
                $row['c_'.$r->col] = $v;
                $row['total'] = Decimal::add($row['total'], $v, 2);
            }
            $rows[] = $row;
        }
        usort($rows, static fn ($a, $b) => [$a['type'] === 'expense', $a['code']] <=> [$b['type'] === 'expense', $b['code']]);

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Budget against actual for the months of the budget that fall in the range.
     *
     * @return list<array<string, mixed>>
     */
    public function budgetVsActual(Budget $budget, string $from, string $to, ?string $branchId): array
    {
        $start = CarbonImmutable::parse($budget->start_date);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->addMonthsNoOverflow($i);
            if ($m->endOfMonth()->toDateString() >= $from && $m->toDateString() <= $to) {
                $months[] = $i;
            }
        }

        $actual = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', $budget->company_id)->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.source_type', '!=', 'year_end_close')
            ->whereBetween('e.posting_date', [$from, $to])
            ->when($branchId !== null, fn ($q) => $q->where('l.branch_id', $branchId))
            ->groupBy('l.account_id')->selectRaw('l.account_id, sum(l.debit) - sum(l.credit) as amount')->pluck('amount', 'account_id');

        $accounts = DB::table('chart_of_accounts')->where('company_id', $budget->company_id)->get(['id', 'code', 'name', 'account_type'])->keyBy('id');

        $rows = [];
        foreach ($budget->lines()->get()->groupBy('account_id') as $accountId => $lines) {
            $planned = '0';
            foreach ($lines as $line) {
                if ($branchId !== null && $line->branch_id !== null && $line->branch_id !== $branchId) {
                    continue;
                }
                foreach ($months as $i) {
                    $planned = Decimal::add($planned, (string) ($line->amounts[$i] ?? '0'), 2);
                }
            }
            $acc = $accounts[$accountId] ?? null;
            if ($acc === null) {
                continue;
            }
            // Revenue and liabilities are credit-natured: show them positive.
            $natural = in_array($acc->account_type, ['revenue', 'liability', 'equity'], true) ? '-1' : '1';
            $act = Decimal::mul((string) ($actual[$accountId] ?? '0'), $natural, 2);
            $variance = Decimal::sub($act, $planned, 2);
            $rows[] = [
                'account_id' => $accountId, 'code' => $acc->code, 'name' => $acc->name, 'type' => $acc->account_type,
                'budget' => $planned, 'actual' => $act, 'variance' => $variance,
                'variance_percent' => Decimal::isZero($planned) ? null : Decimal::div(Decimal::mul($variance, '100', 4), $planned, 1),
                // Spending over budget, or income under it, is unfavourable.
                'favourable' => $acc->account_type === 'revenue' ? ! Decimal::isNegative($variance) : ! Decimal::isPositive($variance),
            ];
        }
        usort($rows, static fn ($a, $b) => $a['code'] <=> $b['code']);

        return $rows;
    }

    /**
     * Cash flow (direct method): every movement on cash and bank accounts,
     * classified by what was on the other side of the entry.
     *
     * @return array{opening: string, closing: string, sections: array<string, list<array{label: string, amount: string}>>, totals: array<string, string>}
     */
    public function cashFlow(string $companyId, string $from, string $to): array
    {
        $cashAccounts = DB::table('bank_accounts')->where('company_id', $companyId)->pluck('account_id')
            ->merge(DB::table('posting_accounts')->where('company_id', $companyId)->whereIn('key', ['cash', 'bank'])->pluck('account_id'))
            ->unique()->values()->all();
        $keys = DB::table('posting_accounts')->where('company_id', $companyId)->pluck('key', 'account_id');

        $balanceAt = fn (string $op, string $date): string => (string) (DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])->whereIn('l.account_id', $cashAccounts)
            ->where('e.posting_date', $op, $date)->selectRaw('COALESCE(sum(l.debit - l.credit), 0) as b')->value('b') ?? '0');

        $entries = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])
            ->whereBetween('e.posting_date', [$from, $to])
            ->whereIn('e.id', DB::table('journal_lines')->whereIn('account_id', $cashAccounts)->select('journal_entry_id'))
            ->get(['l.journal_entry_id', 'l.account_id', 'a.account_type', 'a.name', 'l.debit', 'l.credit'])
            ->groupBy('journal_entry_id');

        $buckets = [];
        foreach ($entries as $lines) {
            $cashDelta = '0';
            $others = [];
            foreach ($lines as $l) {
                if (in_array($l->account_id, $cashAccounts, true)) {
                    $cashDelta = Decimal::add($cashDelta, Decimal::sub((string) $l->debit, (string) $l->credit, 4), 4);
                } else {
                    $others[] = $l;
                }
            }
            if (Decimal::isZero($cashDelta) || $others === []) {
                continue; // transfers between cash accounts
            }
            // Share the cash movement over the other lines in proportion to their size.
            $weight = '0';
            foreach ($others as $o) {
                $weight = Decimal::add($weight, Decimal::sub((string) $o->credit, (string) $o->debit, 4), 4);
            }
            foreach ($others as $o) {
                $own = Decimal::sub((string) $o->credit, (string) $o->debit, 4);
                $share = Decimal::isZero($weight) ? '0' : Decimal::div(Decimal::mul($cashDelta, $own, 8), $weight, 4);
                [$section, $label] = $this->classify($o->account_type, (string) ($keys[$o->account_id] ?? ''), $o->name);
                $buckets[$section][$label] = Decimal::add($buckets[$section][$label] ?? '0', $share, 4);
            }
        }

        $sections = [];
        $totals = [];
        foreach (['operating', 'investing', 'financing'] as $section) {
            $sections[$section] = [];
            $totals[$section] = '0';
            foreach ($buckets[$section] ?? [] as $label => $amount) {
                $sections[$section][] = ['label' => $label, 'amount' => Decimal::round($amount, 2)];
                $totals[$section] = Decimal::add($totals[$section], Decimal::round($amount, 2), 2);
            }
            usort($sections[$section], static fn ($a, $b) => Decimal::cmp($b['amount'], $a['amount']));
        }

        return [
            'opening' => Decimal::round($balanceAt('<', $from), 2),
            'closing' => Decimal::round($balanceAt('<=', $to), 2),
            'sections' => $sections,
            'totals' => $totals + ['net' => Decimal::add(Decimal::add($totals['operating'], $totals['investing'], 2), $totals['financing'], 2)],
        ];
    }

    /** @return array{0: string, 1: string} section and line label */
    private function classify(string $type, string $key, string $name): array
    {
        return match (true) {
            $key === 'accounts_receivable' || $type === 'revenue' => ['operating', 'Received from customers'],
            in_array($key, ['accounts_payable', 'inventory'], true) => ['operating', 'Paid to suppliers'],
            in_array($key, ['salaries_expense', 'salaries_payable', 'employee_advances', 'payroll_deductions_payable'], true) => ['operating', 'Paid to and for staff'],
            in_array($key, ['sales_tax_payable', 'purchase_tax_receivable'], true) => ['operating', 'Sales tax'],
            in_array($key, ['fixed_assets', 'accumulated_depreciation', 'asset_disposal'], true) => ['investing', 'Fixed assets bought and sold'],
            $type === 'expense' => ['operating', $name],
            $type === 'equity' => ['financing', $name],
            $type === 'liability' => ['financing', $name],
            default => ['operating', $name],
        };
    }

    /**
     * Sales tax return: output tax on sales less returns, against input tax
     * on purchases less debit notes, by rate.
     *
     * @return array<string, mixed>
     */
    public function salesTax(string $companyId, string $from, string $to): array
    {
        $t = $this->tenant->requireId();
        $output = DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')
            ->where('s.tenant_id', $t)->where('s.company_id', $companyId)->where('s.status', 'completed')
            ->whereBetween(DB::raw('s.sold_at::date'), [$from, $to])
            ->groupBy('i.tax_rate')->selectRaw('i.tax_rate as rate, sum(i.net_amount) as taxable, sum(i.tax_amount) as tax')->orderBy('rate')->get();
        $returns = DB::table('sales_return_items as i')->join('sales_returns as r', 'r.id', '=', 'i.sales_return_id')
            ->where('r.tenant_id', $t)->where('r.company_id', $companyId)->whereBetween(DB::raw('r.returned_at::date'), [$from, $to])
            ->selectRaw('COALESCE(sum(i.tax_amount), 0) as tax')->value('tax');
        $input = DB::table('goods_receipt_items as i')->join('goods_receipts as g', 'g.id', '=', 'i.goods_receipt_id')
            ->join('purchase_order_items as p', 'p.id', '=', 'i.purchase_order_item_id')
            ->where('g.tenant_id', $t)->where('g.company_id', $companyId)->whereBetween(DB::raw('g.received_at::date'), [$from, $to])
            ->groupBy('p.tax_rate')->selectRaw('p.tax_rate as rate, sum(i.line_total - i.tax_amount) as taxable, sum(i.tax_amount) as tax')->orderBy('rate')->get();
        $inputExpenses = DB::table('supplier_bills')->where('tenant_id', $t)->where('company_id', $companyId)
            ->whereBetween('bill_date', [$from, $to])->selectRaw('COALESCE(sum(expense_tax), 0) as tax')->value('tax');
        $debitNotes = DB::table('purchase_returns')->where('tenant_id', $t)->where('company_id', $companyId)
            ->whereBetween(DB::raw('returned_at::date'), [$from, $to])->selectRaw('COALESCE(sum(tax_total), 0) as tax')->value('tax');

        $outputTax = Decimal::sub(Decimal::sum($output, 'tax', 2), (string) $returns, 2);
        $inputTax = Decimal::sub(Decimal::add(Decimal::sum($input, 'tax', 2), (string) $inputExpenses, 2), (string) $debitNotes, 2);

        return [
            'output_by_rate' => $output, 'sales_returns_tax' => Decimal::round((string) $returns, 2),
            'input_by_rate' => $input, 'expense_input_tax' => Decimal::round((string) $inputExpenses, 2), 'debit_notes_tax' => Decimal::round((string) $debitNotes, 2),
            'output_tax' => $outputTax, 'input_tax' => $inputTax, 'payable' => Decimal::sub($outputTax, $inputTax, 2),
        ];
    }
}
