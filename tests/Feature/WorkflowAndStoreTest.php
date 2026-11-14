<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;
use Tests\TestCase;

/** Approval rules hold an action until approved; the public store takes orders. Needs `erp_test`. */
final class WorkflowAndStoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_stock_adjustment_waits_for_approval_then_runs_as_requested(): void
    {
        $admin = User::query()->where('email', 'admin@brixelltd.com')->firstOrFail();
        Sanctum::actingAs($admin);
        $branch = DB::table('branches')->where('code', 'HO')->value('id');
        $product = DB::table('products')->where('sku', 'HOU-002')->value('id');
        $before = (float) DB::table('stock_balances')->where('product_id', $product)->sum('on_hand');

        $this->postJson('/api/v1/approval-rules', ['document_type' => 'stock_adjustment', 'min_amount' => 1000, 'levels' => ['finance_controller']])->assertCreated();

        // The cashier-level user asks; nothing moves yet.
        $requester = User::query()->where('email', 'cashier@brixelltd.com')->firstOrFail();
        $requester->givePermissionTo('inventory.adjustment.create');
        Sanctum::actingAs($requester);
        $pending = $this->postJson('/api/v1/stock-adjustments', [
            'warehouse_id' => DB::table('warehouses')->where('code', 'WH-MAIN')->value('id'), 'reason_code' => 'damage',
            'lines' => [['product_id' => $product, 'qty_delta' => -5]],
        ], ['X-Branch-ID' => $branch, 'Idempotency-Key' => (string) Str::uuid()])->assertStatus(202)->json('data.approval_request');
        $this->assertEquals($before, (float) DB::table('stock_balances')->where('product_id', $product)->sum('on_hand'));

        // The requester cannot approve; the owner can, and the adjustment then posts.
        $this->postJson("/api/v1/approval-requests/{$pending['id']}/decide", ['decision' => 'approve'])->assertStatus(422);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/approval-requests/{$pending['id']}/decide", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'executed');
        $this->assertEquals($before - 5, (float) DB::table('stock_balances')->where('product_id', $product)->sum('on_hand'));
        $this->assertTrue(DB::table('notifications_inbox')->where('user_id', $requester->id)->exists());
    }

    public function test_public_store_takes_an_order_without_login(): void
    {
        $tenant = DB::table('tenants')->where('slug', 'brixel-demo')->value('id');
        app(TenantContext::class)->set($tenant);
        app(Settings::class)->set('storefront', 'enabled', true);

        $store = $this->getJson('/api/v1/public/stores/brixel-demo')->assertOk()->json('data');
        $item = collect($store['products'])->firstWhere('available', true);

        $order = $this->postJson('/api/v1/public/stores/brixel-demo/orders', [
            'name' => 'Online Buyer', 'phone' => '0333-1234567', 'fulfilment' => 'delivery', 'address' => 'House 1, Block 2, Karachi',
            'items' => [['product_id' => $item['id'], 'quantity' => 2]],
        ])->assertCreated()->json('data');

        $this->assertStringStartsWith('SO', $order['order_no']);
        $this->assertSame('online', DB::table('sales_orders')->where('doc_no', $order['order_no'])->value('source'));
        $this->assertTrue(DB::table('customers')->where('phone', '0333-1234567')->exists());
        $this->postJson('/api/v1/public/stores/brixel-demo/orders', ['name' => 'Bot', 'phone' => '03331234567', 'fulfilment' => 'pickup', 'items' => [['product_id' => $item['id'], 'quantity' => 1]], 'website' => 'spam'])
            ->assertStatus(422);
        $this->getJson('/api/v1/public/stores/no-such-store')->assertNotFound();
    }
}
