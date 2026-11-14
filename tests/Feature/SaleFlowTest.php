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

        $this->product = Product::query()->where('sku', 'BEV-002')->firstOrFail(); // Cola, 100.00 + 18% tax, cost 70
    }

    public function test_a_sale_issues_stock_and_posts_a_balanced_journal(): void
    {
        $this->openShift();

        $sale = $this->sell(quantity: 2, cash: 300)->assertCreated()->json('data');

        $this->assertSame('236.0000', $sale['grand_total']); // 2 × 100 + 18%
        $this->assertSame('64.0000', $sale['change_total']);
        $this->assertSame('140.0000', $sale['cost_total']);

        $this->assertEquals(98, DB::table('stock_balances')->where('product_id', $this->product->id)->value('on_hand'));

        $totals = DB::table('journal_lines')->where('journal_entry_id', $sale['journal_entry_id'])
            ->selectRaw('SUM(debit) AS debit, SUM(credit) AS credit')->first();
        $this->assertSame($totals->debit, $totals->credit);
        $this->assertEquals(376, $totals->debit); // 236 received + 140 cost of goods
    }

    public function test_replaying_a_sale_with_the_same_idempotency_key_creates_one_sale(): void
    {
        $this->openShift();
        $key = (string) Str::uuid();

        $first = $this->sell(quantity: 1, cash: 118, key: $key)->assertCreated();
        $second = $this->sell(quantity: 1, cash: 118, key: $key)->assertCreated();

        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertEquals(99, DB::table('stock_balances')->where('product_id', $this->product->id)->value('on_hand'));
    }

    public function test_selling_more_than_is_in_stock_is_rejected_and_changes_nothing(): void
    {
        $this->openShift();

        $this->sell(quantity: 101, cash: 20000)->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertEquals(100, DB::table('stock_balances')->where('product_id', $this->product->id)->value('on_hand'));
    }

    public function test_voiding_a_sale_restores_stock_and_reverses_the_journal(): void
    {
        $this->openShift();
        $sale = $this->sell(quantity: 3, cash: 354)->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Customer changed mind'])
            ->assertOk()->assertJsonPath('data.status', 'voided');

        $this->assertEquals(100, DB::table('stock_balances')->where('product_id', $this->product->id)->value('on_hand'));
        $this->assertSame('reversed', DB::table('journal_entries')->where('id', $sale['journal_entry_id'])->value('status'));
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
