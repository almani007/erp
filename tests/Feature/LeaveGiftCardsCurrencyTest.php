<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Leave into attendance and payroll; gift cards at the till; foreign-currency bills and exchange gain/loss. Needs `erp_test`. */
final class LeaveGiftCardsCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_approved_unpaid_leave_is_deducted_by_payroll_and_balance_is_enforced(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $employee = DB::table('employees')->where('code', 'EMP-002')->first();
        $unpaid = DB::table('leave_types')->where('code', 'UL')->value('id');
        $sick = DB::table('leave_types')->where('code', 'SL')->value('id');
        $monday = now()->startOfMonth()->next('Monday');

        // Two days of unpaid leave: approved → written to attendance as unpaid.
        $leave = $this->postJson('/api/v1/leave-requests', [
            'employee_id' => $employee->id, 'leave_type_id' => $unpaid,
            'from_date' => $monday->toDateString(), 'to_date' => $monday->copy()->addDay()->toDateString(), 'reason' => 'Travel',
        ])->assertCreated()->json('data');
        $this->assertEquals(2, $leave['days']);
        $this->postJson("/api/v1/leave-requests/{$leave['id']}/decide", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(2, DB::table('attendance')->where('employee_id', $employee->id)->where('status', 'unpaid_leave')->count());

        // Overlapping dates are refused; sick leave beyond the 8-day allowance is refused.
        $this->postJson('/api/v1/leave-requests', ['employee_id' => $employee->id, 'leave_type_id' => $sick,
            'from_date' => $monday->toDateString(), 'to_date' => $monday->toDateString()])->assertStatus(422);
        $this->postJson('/api/v1/leave-requests', ['employee_id' => $employee->id, 'leave_type_id' => $sick,
            'from_date' => $monday->copy()->addWeeks(2)->toDateString(), 'to_date' => $monday->copy()->addWeeks(4)->toDateString()])->assertStatus(422);

        // Payroll counts the two unpaid days as absent.
        $run = $this->postJson('/api/v1/payroll-runs', ['company_id' => DB::table('companies')->value('id'), 'period' => now()->format('Y-m')])->assertCreated()->json('data');
        $line = collect($run['lines'])->firstWhere('employee_id', $employee->id);
        $this->assertGreaterThanOrEqual(2, (float) $line['absent_days']);
        $this->assertGreaterThan(0, (float) $line['absence_deduction']);
    }

    public function test_gift_card_pays_at_the_till_and_comes_back_on_void(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $this->postJson('/api/v1/pos/shifts/open', ['terminal_id' => DB::table('pos_terminals')->value('id'), 'opening_cash' => 1000])->assertCreated();
        $product = DB::table('products')->where('sku', 'HOU-002')->value('id'); // 480 + 18 % = 566.40

        $this->getJson('/api/v1/gift-cards/lookup/gc-demo-5000')->assertOk()->assertJsonPath('data.balance', '5000.0000');

        $sale = $this->postJson('/api/v1/sales', [
            'lines' => [['product_id' => $product, 'quantity' => 1]],
            'tenders' => [['method' => 'gift_card', 'amount' => 500, 'reference' => 'GC-DEMO-5000'], ['method' => 'cash', 'amount' => 66.40]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertEquals(4500, (float) DB::table('gift_cards')->where('code', 'GC-DEMO-5000')->value('balance'));

        // More than the card holds is refused.
        $this->postJson('/api/v1/sales', [
            'lines' => [['product_id' => $product, 'quantity' => 10]],
            'tenders' => [['method' => 'gift_card', 'amount' => 5000, 'reference' => 'GC-DEMO-5000'], ['method' => 'cash', 'amount' => 664]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);

        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'])->assertOk();
        $this->assertEquals(5000, (float) DB::table('gift_cards')->where('code', 'GC-DEMO-5000')->value('balance'));
    }

    public function test_foreign_bill_paid_at_a_new_rate_books_exchange_loss_and_revaluation_previews(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $company = DB::table('companies')->value('id');
        $supplier = DB::table('suppliers')->orderBy('supplier_code')->first();
        $freight = DB::table('chart_of_accounts')->where('code', '5300')->value('id');

        // USD 100 of charges at 280 → 28,000 owed in the books.
        $bill = $this->postJson('/api/v1/supplier-bills', [
            'supplier_id' => $supplier->id, 'supplier_invoice_no' => 'USD-1', 'bill_date' => today()->toDateString(),
            'goods_receipt_ids' => [], 'expenses' => [['account_id' => $freight, 'description' => 'Sea freight', 'amount' => 100]],
            'currency' => 'USD', 'fx_rate' => 280,
        ], $key())->assertCreated()->json('data');
        $this->assertSame('28000.0000', $bill['grand_total']);
        $this->assertSame('100.0000', $bill['foreign_balance_due']);

        // A second bill stays open for the revaluation preview.
        $this->postJson('/api/v1/supplier-bills', [
            'supplier_id' => $supplier->id, 'supplier_invoice_no' => 'USD-2', 'bill_date' => today()->toDateString(),
            'goods_receipt_ids' => [], 'expenses' => [['account_id' => $freight, 'description' => 'Air freight', 'amount' => 50]],
            'currency' => 'USD', 'fx_rate' => 280,
        ], $key())->assertCreated();

        // Paid USD 100 at 285 → 28,500 out of the bank, 500 exchange loss.
        $payment = $this->postJson("/api/v1/suppliers/{$supplier->id}/payments", [
            'company_id' => $company, 'method' => 'bank', 'amount' => 100, 'currency' => 'USD', 'fx_rate' => 285,
            'allocations' => [['supplier_bill_id' => $bill['id'], 'amount' => 100]],
        ], $key())->assertCreated()->json('data');
        $this->assertSame('28500.0000', $payment['amount']);
        $this->assertSame('500.0000', $payment['fx_difference']);
        $this->assertSame('paid', DB::table('supplier_bills')->where('id', $bill['id'])->value('status'));
        $fxAccount = DB::table('posting_accounts')->where('company_id', $company)->where('key', 'fx_realized')->value('account_id');
        $this->assertEquals(500, (float) DB::table('journal_lines')->where('account_id', $fxAccount)->sum('debit'));

        // Rate rises to 290: the open USD 50 bill would cost 500 more.
        $this->postJson('/api/v1/exchange-rates', ['rates' => [['currency' => 'USD', 'rate_date' => today()->toDateString(), 'rate' => 290]]])->assertCreated();
        $preview = $this->getJson('/api/v1/fx/exposure?as_of='.today()->toDateString())->assertOk()->json('data.revaluation');
        $this->assertSame('500.00', $preview['net_adjustment']);
        $this->postJson('/api/v1/fx/revaluations', ['as_of' => today()->toDateString()])->assertCreated();
        $this->assertSame('14000.0000', DB::table('supplier_bills')->where('supplier_invoice_no', 'USD-2')->value('balance_due')); // bills keep their rate
    }
}
