<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Restaurant, appointments and rentals end to end. Needs `erp_test` (see SaleFlowTest). */
final class ServiceBusinessesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
    }

    private function headers(): array
    {
        return ['X-Branch-ID' => DB::table('branches')->where('code', 'HO')->value('id'), 'Idempotency-Key' => (string) Str::uuid()];
    }

    public function test_table_order_goes_through_the_kitchen_and_is_settled(): void
    {
        $h = $this->headers();
        $this->postJson('/api/v1/pos/shifts/open', ['terminal_id' => DB::table('pos_terminals')->value('id'), 'opening_cash' => 1000], $h)->assertCreated();
        $table = DB::table('dining_tables')->where('name', 'T1')->value('id');
        $chai = DB::table('products')->where('sku', 'CAF-CHAI')->value('id');
        $milkBefore = (float) DB::table('stock_balances')->where('product_id', DB::table('products')->where('sku', 'DAI-001')->value('id'))->sum('on_hand');

        $order = $this->postJson('/api/v1/restaurant/orders', ['order_type' => 'dine_in', 'table_id' => $table, 'guests' => 2], $h)->assertCreated()->json('data');
        $this->postJson('/api/v1/restaurant/orders', ['order_type' => 'dine_in', 'table_id' => $table], $h)->assertStatus(422); // table taken

        // Sugar is a required choice.
        $this->postJson("/api/v1/restaurant/orders/{$order['id']}/items", ['items' => [['product_id' => $chai, 'quantity' => 2]]], $h)->assertStatus(422);
        $order = $this->postJson("/api/v1/restaurant/orders/{$order['id']}/items", ['items' => [['product_id' => $chai, 'quantity' => 2, 'modifier_option_ids' => ['sugar-gur'], 'note' => 'strong']]], $h)
            ->assertOk()->json('data');
        $this->assertSame('140.0000', $order['lines'][0]['unit_price']); // 120 + 20 for gur

        $order = $this->postJson("/api/v1/restaurant/orders/{$order['id']}/send", [], $h)->assertOk()->json('data');
        $tickets = $this->getJson('/api/v1/restaurant/kitchen', $h)->assertOk()->json('data');
        $this->assertCount(1, $tickets);
        $this->postJson("/api/v1/restaurant/kitchen/lines/{$order['lines'][0]['id']}", ['status' => 'ready'], $h)->assertOk();

        $sale = $this->postJson("/api/v1/restaurant/orders/{$order['id']}/settle", ['tenders' => [['method' => 'cash', 'amount' => 500]]], $this->headers())
            ->assertCreated()->json('data');
        $this->assertSame('280.0000', $sale['grand_total']); // exempt from tax
        $this->assertSame('paid', DB::table('dine_orders')->where('id', $order['id'])->value('status'));
        $milkAfter = (float) DB::table('stock_balances')->where('product_id', DB::table('products')->where('sku', 'DAI-001')->value('id'))->sum('on_hand');
        $this->assertEqualsWithDelta($milkBefore - 0.3, $milkAfter, 0.0001); // two cups use their milk
    }

    public function test_appointments_refuse_clashes_and_closed_hours(): void
    {
        $h = $this->headers();
        $resource = DB::table('booking_resources')->value('id');
        $service = DB::table('products')->where('sku', 'SRV-HAIRCUT')->value('id');
        $monday = now('Asia/Karachi')->next('Monday')->setTime(12, 0); // the branch's time zone

        $this->postJson('/api/v1/appointments', ['resource_id' => $resource, 'product_id' => $service, 'starts_at' => $monday->toIso8601String(), 'customer_name' => 'Ayesha'], $h)->assertCreated();
        $this->postJson('/api/v1/appointments', ['resource_id' => $resource, 'product_id' => $service, 'starts_at' => $monday->copy()->addMinutes(30)->toIso8601String(), 'customer_name' => 'Hina'], $h)
            ->assertStatus(422); // overlaps 12:00–12:45
        $this->postJson('/api/v1/appointments', ['resource_id' => $resource, 'product_id' => $service, 'starts_at' => $monday->copy()->setTime(15, 10)->toIso8601String(), 'customer_name' => 'Sara'], $h)
            ->assertStatus(422); // lunch break 15:00–16:00
        $slots = $this->getJson('/api/v1/appointments/slots?product_id='.$service.'&date='.$monday->toDateString(), $h)->assertOk()->json('data');
        $this->assertNotContains($monday->toIso8601String(), array_column($slots, 'start'));
    }

    public function test_rental_bills_rent_holds_deposit_and_settles_on_return(): void
    {
        $customer = DB::table('customers')->where('customer_code', 'CUS-000003')->value('id');
        $contract = $this->postJson('/api/v1/rental-contracts', [
            'warehouse_id' => DB::table('warehouses')->where('code', 'WH-MAIN')->value('id'), 'customer_id' => $customer,
            'rent_product_id' => DB::table('products')->where('sku', 'RNT-GENSET')->value('id'), 'item_description' => '20 kVA generator',
            'start_date' => today()->subMonthsNoOverflow(2)->toDateString(), 'billing_frequency' => 'monthly', 'rent_amount' => 15000,
            'deposit_amount' => 50000, 'deposit_method' => 'cash',
        ], $this->headers())->assertCreated()->json('data');

        $this->postJson('/api/v1/rental-contracts/bill-due')->assertOk()->assertJsonPath('data.invoiced', 3); // two months back, plus this month
        $this->assertEquals(45000, (float) DB::table('sales')->where('rental_contract_id', $contract['id'])->sum('grand_total'));

        $this->postJson("/api/v1/rental-contracts/{$contract['id']}/return", ['date' => today()->toDateString(), 'deduction' => 5000, 'refund_method' => 'cash'], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'returned')->assertJsonPath('data.deposit_refunded', '45000.00');
    }
}
