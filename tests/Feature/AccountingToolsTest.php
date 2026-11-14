<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Recurring journals, budgets, management reports and year-end close. Needs `erp_test` (see SaleFlowTest). */
final class AccountingToolsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_recurring_budget_reports_and_year_end(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $account = fn (string $code) => DB::table('chart_of_accounts')->where('code', $code)->value('id');
        $range = 'from='.now()->startOfYear()->toDateString().'&to='.today()->toDateString();

        // Rent of 50,000 a month from the bank.
        $template = $this->postJson('/api/v1/recurring-journals', [
            'name' => 'Shop rent', 'frequency' => 'monthly', 'next_run_date' => today()->toDateString(),
            'lines' => [['account_id' => $account('5300'), 'debit' => 50000], ['account_id' => $account('1020'), 'credit' => 50000]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/recurring-journals/run')->assertOk()->assertJsonPath('data.posted', 1);
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), DB::table('recurring_journals')->where('id', $template['id'])->value('next_run_date'));

        // Unbalanced templates are refused.
        $this->postJson('/api/v1/recurring-journals', [
            'name' => 'Bad', 'frequency' => 'monthly', 'next_run_date' => today()->toDateString(),
            'lines' => [['account_id' => $account('5300'), 'debit' => 10], ['account_id' => $account('1020'), 'credit' => 9]],
        ])->assertStatus(422);

        $amounts = array_fill(0, 12, 40000);
        $budget = $this->postJson('/api/v1/budgets', [
            'name' => 'Plan '.now()->year, 'start_date' => now()->startOfYear()->toDateString(),
            'lines' => [['account_id' => $account('5300'), 'amounts' => $amounts]],
        ])->assertCreated()->json('data');
        $row = collect($this->getJson("/api/v1/reports/budget-vs-actual/{$budget['id']}?{$range}")->assertOk()->json('data'))->firstWhere('code', '5300');
        $this->assertSame('50000.00', $row['actual']);
        $this->assertFalse($row['favourable'] && $row['budget'] === '40000.00'); // over budget in the current month

        $this->getJson("/api/v1/reports/profit-loss-matrix?{$range}")->assertOk();
        $this->getJson("/api/v1/reports/cash-flow?{$range}")->assertOk();
        $this->getJson("/api/v1/reports/sales-tax?{$range}")->assertOk();

        $closing = $this->postJson('/api/v1/year-end-closings', ['year_start' => now()->startOfYear()->toDateString(), 'year_end' => today()->toDateString()])
            ->assertCreated()->json('data');
        $income = DB::table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('a.account_type', ['revenue', 'expense'])->whereIn('e.status', ['posted', 'reversed'])->selectRaw('sum(l.debit) - sum(l.credit) as b')->value('b');
        $this->assertEquals(0, (float) $income); // every income and expense account is now at zero

        $this->postJson("/api/v1/year-end-closings/{$closing['id']}/reopen", ['reason' => 'Late invoices'])->assertOk()->assertJsonPath('data.status', 'reopened');
    }
}
