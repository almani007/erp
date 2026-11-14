<?php

declare(strict_types=1);

namespace Tests\Feature;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Organization\Infrastructure\Persistence\Eloquent\Tenant;
use Shared\Domain\Contract\TenantContext;
use Tests\TestCase;

/**
 * End-to-end checks of the release-blocking invariants (SRS §13.2) against
 * a real PostgreSQL database: the ledger triggers are part of what is tested.
 *
 * Needs the `erp_test` database:
 *   docker compose exec postgres createdb -U erp_app erp_test
 */
final class SaleFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->where('email', 'admin@brixelltd.com')->firstOrFail();
        Sanctum::actingAs($this->admin);

        $this->product = Product::query()->where('sku', 'HOU-002')->firstOrFail(); // Detergent: 480.00 + 18% tax, cost 390, no deal running
    }

    public function test_a_sale_issues_stock_and_posts_a_balanced_journal(): void
    {
        $this->openShift();

        $sale = $this->sell(quantity: 2, cash: 1200)->assertCreated()->json('data');

        $this->assertSame('1132.8000', $sale['grand_total']); // 2 × 480 + 18%
        $this->assertSame('67.2000', $sale['change_total']);
        $this->assertSame('780.0000', $sale['cost_total']);

        $this->assertEquals(98, DB::table('stock_balances')->where('product_id', $this->product->id)->value('on_hand'));

        $totals = DB::table('journal_lines')->where('journal_entry_id', $sale['journal_entry_id'])
            ->selectRaw('SUM(debit) AS debit, SUM(credit) AS credit')->first();
        $this->assertSame($totals->debit, $totals->credit);
        $this->assertEquals(1912.8, $totals->debit); // 1132.80 received + 780 cost of goods
    }

    public function test_replaying_a_sale_with_the_same_idempotency_key_creates_one_sale(): void
    {
        $this->openShift();
        $key = (string) Str::uuid();

        $first = $this->sell(quantity: 1, cash: 600, key: $key)->assertCreated();
        $second = $this->sell(quantity: 1, cash: 600, key: $key)->assertCreated();

        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertEquals(99, DB::table('stock_balances')->where('product_id', $this->product->id)->value('on_hand'));
    }

    public function test_selling_more_than_is_in_stock_is_rejected_and_changes_nothing(): void
    {
        $this->openShift();

        $this->sell(quantity: 101, cash: 100000)->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertEquals(100, DB::table('stock_balances')->where('product_id', $this->product->id)->value('on_hand'));
    }

    public function test_voiding_a_sale_restores_stock_and_reverses_the_journal(): void
    {
        $this->openShift();
        $sale = $this->sell(quantity: 3, cash: 1700)->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Customer changed mind'])
            ->assertOk()->assertJsonPath('data.status', 'voided');

        $this->assertEquals(100, DB::table('stock_balances')->where('product_id', $this->product->id)->value('on_hand'));
        $this->assertSame('reversed', DB::table('journal_entries')->where('id', $sale['journal_entry_id'])->value('status'));
    }

    public function test_a_partial_return_refunds_its_share_and_restocks(): void
    {
        $this->openShift();
        $sale = $this->sell(quantity: 2, cash: 1200)->json('data');

        $return = $this->postJson("/api/v1/sales/{$sale['id']}/returns", [
            'reason' => 'Damaged packaging',
            'refund_method' => 'bank',
            'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->assertSame('566.4000', $return['grand_total']);
        $this->assertEquals(99, DB::table('stock_balances')->where('product_id', $this->product->id)->value('on_hand'));

        // Only one unit is left to return.
        $this->postJson("/api/v1/sales/{$sale['id']}/returns", [
            'reason' => 'Second attempt',
            'refund_method' => 'bank',
            'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_a_running_deal_and_a_coupon_reduce_the_price(): void
    {
        $cola = Product::query()->where('sku', 'BEV-002')->firstOrFail(); // 100.00, in the 10% beverage deal
        $this->openShift();

        $quote = $this->postJson('/api/v1/pos/quote', ['lines' => [['product_id' => $cola->id, 'quantity' => 2]]])->assertOk()->json('data');
        $this->assertSame('20.0000', $quote['discount_total']);
        $this->assertSame('212.4000', $quote['grand_total']); // (200 − 20) + 18%

        $this->postJson('/api/v1/pos/quote', ['lines' => [['product_id' => $cola->id, 'quantity' => 1]], 'coupon_code' => 'NOPE'])->assertStatus(422);
    }

    public function test_wholesale_packs_and_market_rate_pricing(): void
    {
        $this->openShift();
        $sugar = Product::query()->where('sku', 'GRO-004')->firstOrFail();   // retail 165, wholesale 152 from 10 kg
        $cola = Product::query()->with('units')->where('sku', 'BEV-002')->firstOrFail(); // carton of 24 at 2280, 10% deal
        $ring = Product::query()->where('sku', 'JWL-RING-001')->firstOrFail(); // 8.5 g × 38,000 + 6% wastage + 12,000 making

        $quote = fn (array $body) => $this->postJson('/api/v1/pos/quote', $body)->assertOk()->json('data');

        // Wholesale applies only at the wholesale tier and from the minimum quantity.
        $this->assertSame('165.0000', $quote(['lines' => [['product_id' => $sugar->id, 'quantity' => 10]]])['lines'][0]['unit_price']);
        $this->assertSame('152.0000', $quote(['lines' => [['product_id' => $sugar->id, 'quantity' => 10]], 'price_tier' => 'wholesale'])['lines'][0]['unit_price']);
        $this->assertSame('165.0000', $quote(['lines' => [['product_id' => $sugar->id, 'quantity' => 9]], 'price_tier' => 'wholesale'])['lines'][0]['unit_price']);

        // Jewellery is priced from today's rate; changing the rate changes the price.
        $this->assertSame('354380.0000', $quote(['lines' => [['product_id' => $ring->id, 'quantity' => 1]]])['grand_total']);

        // Selling one carton takes 24 units out of stock.
        $carton = $cola->units->first();
        $sale = $this->postJson('/api/v1/sales', [
            'lines' => [['product_id' => $cola->id, 'unit_id' => $carton->id, 'quantity' => 1]],
            'tenders' => [['method' => 'cash', 'amount' => 3000]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->assertSame('2421.3600', $sale['grand_total']); // (2280 − 10%) + 18%
        $this->assertEquals(76, DB::table('stock_balances')->where('product_id', $cola->id)->value('on_hand'));
    }

    public function test_expiry_tracked_stock_is_sold_from_its_batch_and_expired_stock_is_refused(): void
    {
        $this->openShift();
        $medicine = Product::query()->where('sku', 'PHA-PARA-500')->firstOrFail(); // 100 in batch B-0001
        $lot = fn () => DB::table('stock_lots')->where('product_id', $medicine->id)->where('batch_no', 'B-0001');

        $sell = fn (int $quantity) => $this->postJson('/api/v1/sales', [
            'lines' => [['product_id' => $medicine->id, 'quantity' => $quantity]],
            'tenders' => [['method' => 'cash', 'amount' => 1000]],
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $sell(5)->assertCreated();
        $this->assertEquals(95, $lot()->value('quantity'));

        // Once the batch has expired it can no longer be sold.
        $lot()->update(['expiry_date' => now()->subDay()->toDateString()]);
        $sell(1)->assertStatus(422);
        $this->assertEquals(95, $lot()->value('quantity'));
    }

    public function test_serial_tracked_products_need_one_serial_per_unit_and_can_be_looked_up(): void
    {
        $this->openShift();
        $phone = Product::query()->where('sku', 'ELE-PHONE-A1')->firstOrFail(); // 48,500 + 18%, 12 months warranty

        $sell = fn (array $serials) => $this->postJson('/api/v1/sales', [
            'lines' => [['product_id' => $phone->id, 'quantity' => 1, 'serials' => $serials]],
            'tenders' => [['method' => 'card', 'amount' => 57230, 'reference' => 'TEST']],
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $sell([])->assertStatus(422);
        $sell(['imei-0001'])->assertCreated();
        $sell(['IMEI-0001'])->assertStatus(422); // the same unit cannot be sold twice

        $this->getJson('/api/v1/serials?serial=imei-0001')->assertOk()
            ->assertJsonPath('data.0.serial', 'IMEI-0001')
            ->assertJsonPath('data.0.in_warranty', true);
    }

    public function test_posted_journal_lines_cannot_be_changed_or_deleted(): void
    {
        $line = DB::table('journal_lines')->first(); // from the seeded opening stock

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('journal_lines')->where('id', $line->id)->update(['debit' => 1]);
    }

    public function test_one_tenant_cannot_read_another_tenants_records(): void
    {
        $other = Tenant::query()->create(['slug' => 'other-co', 'legal_name' => 'Other Co', 'status' => 'active']);
        $outsider = new User(['name' => 'Outsider', 'email' => 'outsider@example.com', 'password' => 'irrelevant-password', 'status' => 'active', 'all_branches' => true]);
        $outsider->tenant_id = $other->id;
        $outsider->save();
        $outsider->assignRole('tenant_owner');

        Sanctum::actingAs($outsider);
        app()->forgetScopedInstances(); // contexts are per request

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/products/{$this->product->id}")->assertNotFound();
        $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(0, 'data');
    }

    private function openShift(): void
    {
        $terminalId = DB::table('pos_terminals')->value('id');

        $this->postJson('/api/v1/pos/shifts/open', ['terminal_id' => $terminalId, 'opening_cash' => 1000])->assertCreated();
    }

    private function sell(int $quantity, int $cash, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/sales', [
            'lines' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
            'tenders' => [['method' => 'cash', 'amount' => $cash]],
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
