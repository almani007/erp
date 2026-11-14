<?php

declare(strict_types=1);

namespace Tests\Feature;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Recipes: production orders and ingredients taken out on sale. Needs `erp_test` (see SaleFlowTest). */
final class ProductionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
    }

    public function test_completing_production_moves_materials_into_finished_stock_at_their_cost(): void
    {
        $recipeId = DB::table('recipes')->where('product_id', $this->sku('MFG-LASSI')->id)->value('id');
        $warehouseId = DB::table('warehouses')->where('code', 'WH-MAIN')->value('id');
        $yogurtBefore = $this->onHand('DAI-002');

        $order = $this->postJson('/api/v1/production-orders', ['recipe_id' => $recipeId, 'warehouse_id' => $warehouseId, 'quantity' => 10],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $done = $this->postJson("/api/v1/production-orders/{$order['id']}/complete", ['produced_quantity' => 10, 'extra_cost' => 100],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');

        // 10 yogurt + 2% wastage = 10.2 at 110, 0.5 kg sugar at 140 → 1122 + 70; plus 100 labour → 129.20 a bottle.
        $this->assertSame('completed', $done['status']);
        $this->assertSame('129.2000', $done['unit_cost']);
        $this->assertEquals($yogurtBefore - 10.2, $this->onHand('DAI-002'));
        $this->assertEquals(10, $this->onHand('MFG-LASSI'));
        $this->assertNotNull($done['journal_entry_id']);

        $this->postJson("/api/v1/production-orders/{$order['id']}/complete", ['produced_quantity' => 10],
            ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422); // only once
    }

    public function test_selling_a_recipe_item_takes_ingredients_and_a_void_puts_them_back(): void
    {
        $milkBefore = $this->onHand('DAI-001');
        $this->postJson('/api/v1/pos/shifts/open', ['terminal_id' => DB::table('pos_terminals')->value('id'), 'opening_cash' => 1000])->assertCreated();

        $sale = $this->postJson('/api/v1/sales', [
            'lines' => [['product_id' => $this->sku('CAF-CHAI')->id, 'quantity' => 4]],
            'tenders' => [['method' => 'cash', 'amount' => 480]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->assertEquals($milkBefore - 0.6, $this->onHand('DAI-001'));
        $this->assertTrue((float) $sale['cost_total'] > 0); // ingredient cost reaches cost of goods sold

        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'wrong order'])->assertOk();
        $this->assertEquals($milkBefore, $this->onHand('DAI-001'));
    }

    private function sku(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    private function onHand(string $sku): float
    {
        return (float) DB::table('stock_balances')->where('product_id', $this->sku($sku)->id)->sum('on_hand');
    }
}
