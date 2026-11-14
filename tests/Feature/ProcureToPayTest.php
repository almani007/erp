<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Order → receipt → supplier bill (three-way match) → debit note → payment. Needs `erp_test` (see SaleFlowTest). */
final class ProcureToPayTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_bill_matches_receipts_and_payment_clears_it(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $supplier = DB::table('suppliers')->orderBy('supplier_code')->first();
        $product = DB::table('products')->where('sku', 'HOU-002')->first();
        $warehouse = DB::table('warehouses')->where('code', 'WH-MAIN')->value('id');
        $company = DB::table('companies')->value('id');

        $po = $this->postJson('/api/v1/purchase-orders', [
            'warehouse_id' => $warehouse, 'supplier_id' => $supplier->id, 'order_date' => today()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 100, 'unit_cost' => 400, 'tax_rate' => 0]],
        ], $key())->assertCreated()->json('data');
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/approve")->assertOk();
        $item = DB::table('purchase_order_items')->where('purchase_order_id', $po['id'])->first();
        $grn = $this->postJson("/api/v1/purchase-orders/{$po['id']}/receive", ['lines' => [['purchase_order_item_id' => $item->id, 'quantity' => 100]]], $key())
            ->assertCreated()->json('data');
        $balanceAfterReceipt = (float) DB::table('suppliers')->where('id', $supplier->id)->value('balance');

        // Their invoice is 40,500 for goods we received at 40,000: 1.25 % over, inside the 2 % tolerance.
        $bill = $this->postJson('/api/v1/supplier-bills', [
            'supplier_id' => $supplier->id, 'supplier_invoice_no' => 'SUP-778', 'bill_date' => today()->toDateString(),
            'goods_receipt_ids' => [$grn['id']], 'invoice_goods_amount' => 40500, 'expenses' => [],
        ], $key())->assertCreated()->json('data');
        $this->assertSame('500.0000', $bill['variance']);
        $this->assertSame('40500.0000', $bill['balance_due']);
        $this->assertEquals($balanceAfterReceipt + 500, (float) DB::table('suppliers')->where('id', $supplier->id)->value('balance'));

        // The same supplier invoice cannot be entered twice.
        $this->postJson('/api/v1/supplier-bills', [
            'supplier_id' => $supplier->id, 'supplier_invoice_no' => 'SUP-778', 'bill_date' => today()->toDateString(),
            'goods_receipt_ids' => [], 'expenses' => [['account_id' => DB::table('chart_of_accounts')->where('code', '5300')->value('id'), 'description' => 'x', 'amount' => 10]],
        ], $key())->assertStatus(422);

        // Ten damaged units go back: the bill is owed 4,000 less.
        $grnItem = DB::table('goods_receipt_items')->where('goods_receipt_id', $grn['id'])->first();
        $this->postJson('/api/v1/purchase-returns', [
            'goods_receipt_id' => $grn['id'], 'reason' => 'Damaged cartons',
            'lines' => [['goods_receipt_item_id' => $grnItem->id, 'quantity' => 10]],
        ], $key())->assertCreated();
        $this->assertSame('36500.0000', DB::table('supplier_bills')->where('id', $bill['id'])->value('balance_due'));

        $this->postJson("/api/v1/suppliers/{$supplier->id}/payments", ['company_id' => $company, 'method' => 'bank', 'amount' => 36500], $key())->assertCreated();
        $this->assertSame('paid', DB::table('supplier_bills')->where('id', $bill['id'])->value('status'));

        $this->assertEquals(0, (float) DB::table('suppliers')->where('id', $supplier->id)->value('balance'));
        $this->getJson('/api/v1/payables/aging')->assertOk();
        $this->getJson("/api/v1/suppliers/{$supplier->id}/statement?from=".today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()->assertJsonPath('data.closing', '0.00');
    }
}
