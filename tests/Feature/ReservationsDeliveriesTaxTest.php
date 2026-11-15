<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use People\Application\IncomeTax;
use Tests\TestCase;

/** Orders hold stock; deliveries with cash on delivery; salary tax from slabs; offline sale time. Needs `erp_test`. */
final class ReservationsDeliveriesTaxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_open_order_holds_stock_until_invoiced_and_delivery_collects_cash(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $customer = DB::table('customers')->where('customer_code', 'CUS-000003')->first();
        $product = DB::table('products')->where('sku', 'HOU-002')->first();
        $warehouse = DB::table('warehouses')->where('code', 'WH-MAIN')->value('id');
        $onHand = (float) DB::table('stock_balances')->where('product_id', $product->id)->where('warehouse_id', $warehouse)->value('on_hand');

        // An order for 5 units holds them.
        $order = $this->postJson('/api/v1/sales-documents', [
            'doc_type' => 'order', 'warehouse_id' => $warehouse, 'customer_id' => $customer->id, 'doc_date' => today()->toDateString(), 'payment_days' => 15,
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 450]],
        ])->assertCreated()->json('data');
        $this->assertSame([], $order['stock_shortages']);
        $this->assertEquals(5, (float) DB::table('stock_balances')->where('product_id', $product->id)->where('warehouse_id', $warehouse)->value('reserved'));

        // The till cannot sell into the held units.
        $this->postJson('/api/v1/pos/shifts/open', ['terminal_id' => DB::table('pos_terminals')->value('id'), 'opening_cash' => 1000])->assertCreated();
        $this->postJson('/api/v1/sales', ['lines' => [['product_id' => $product->id, 'quantity' => $onHand - 4]], 'tenders' => [['method' => 'cash', 'amount' => 99999999]]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);

        // Invoicing the order uses its own reservation.
        $invoice = $this->postJson("/api/v1/sales-documents/{$order['id']}/invoice", [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertEquals(0, (float) DB::table('stock_balances')->where('product_id', $product->id)->where('warehouse_id', $warehouse)->value('reserved'));

        // Deliver it with cash on delivery: the cash becomes a receipt against the invoice.
        $note = $this->postJson('/api/v1/deliveries', ['sale_id' => $invoice['id'], 'collect_cash' => true, 'driver_name' => 'Rider One'])->assertCreated()->json('data');
        $this->assertSame($invoice['balance_due'], $note['cod_amount']);
        $this->postJson('/api/v1/deliveries', ['sale_id' => $invoice['id']])->assertStatus(422); // nothing left to send
        $this->postJson("/api/v1/deliveries/{$note['id']}/dispatch", [])->assertOk()->assertJsonPath('data.status', 'dispatched');
        $this->postJson("/api/v1/deliveries/{$note['id']}/deliver", ['received_by' => 'Shop owner', 'collected_amount' => 1000],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertEquals((float) $invoice['balance_due'] - 1000, (float) DB::table('sales')->where('id', $invoice['id'])->value('balance_due'));
    }

    public function test_salary_tax_from_default_slabs(): void
    {
        $tax = app(IncomeTax::class);
        $rules = ['slabs' => IncomeTax::DEFAULT_SLABS, 'year_start_month' => 7, 'surcharge_threshold' => '10000000', 'surcharge_rate' => '9'];

        $this->assertSame('0.00', $tax->annual('600000', $rules));
        $this->assertSame('6000.00', $tax->annual('1200000', $rules));          // 1 % of 600,000
        $this->assertSame('61000.00', $tax->annual('1700000', $rules));         // 6,000 + 11 % of 500,000
        $this->assertSame('185000.00', $tax->annual('2500000', $rules));        // 116,000 + 23 % of 300,000
        $this->assertSame('3685290.00', $tax->annual('12000000', $rules));      // (616,000 + 35 % of 7.9 m) + 9 % surcharge

        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $this->postJson('/api/v1/payroll/tax-calculator', ['annual_income' => 1700000])->assertOk()->assertJsonPath('data.annual_tax', '61000.00');
        $this->putJson('/api/v1/payroll/tax-slabs', [
            'slabs' => [['from' => 0, 'to' => 500000, 'fixed' => 0, 'rate' => 0], ['from' => 600000, 'to' => null, 'fixed' => 0, 'rate' => 5]],
            'year_start_month' => 7, 'surcharge_threshold' => 10000000, 'surcharge_rate' => 9,
        ])->assertStatus(422); // a gap between slabs
    }

    public function test_offline_sale_keeps_the_time_it_was_rung_up(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $this->postJson('/api/v1/pos/shifts/open', ['terminal_id' => DB::table('pos_terminals')->value('id'), 'opening_cash' => 1000])->assertCreated();
        $soldAt = now()->addSeconds(1);
        $this->travel(10)->minutes();

        $sale = $this->postJson('/api/v1/sales', [
            'lines' => [['product_id' => DB::table('products')->where('sku', 'HOU-002')->value('id'), 'quantity' => 1]],
            'tenders' => [['method' => 'cash', 'amount' => 600]], 'offline_sold_at' => $soldAt->toIso8601String(),
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->assertSame($soldAt->format('Y-m-d H:i'), \Carbon\Carbon::parse(DB::table('sales')->where('id', $sale['id'])->value('sold_at'))->format('Y-m-d H:i'));
    }
}
