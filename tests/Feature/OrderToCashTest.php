<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Quotation → order → partial invoices → receipt → aging and statement. Needs `erp_test` (see SaleFlowTest). */
final class OrderToCashTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_quotation_to_order_to_invoices_and_payment(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $customer = DB::table('customers')->where('customer_code', 'CUS-000003')->first();
        $product = DB::table('products')->where('sku', 'HOU-002')->first();
        $warehouse = DB::table('warehouses')->where('code', 'WH-MAIN')->value('id');
        $stockBefore = (float) DB::table('stock_balances')->where('product_id', $product->id)->where('warehouse_id', $warehouse)->value('on_hand');

        $quote = $this->postJson('/api/v1/sales-documents', [
            'doc_type' => 'quotation', 'warehouse_id' => $warehouse, 'customer_id' => $customer->id,
            'doc_date' => today()->toDateString(), 'valid_until' => today()->addDays(15)->toDateString(), 'payment_days' => 30,
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 450]],
        ])->assertCreated()->json('data');
        $this->assertSame('5310.0000', $quote['grand_total']); // 4,500 + 18 %

        $this->postJson("/api/v1/sales-documents/{$quote['id']}/status", ['status' => 'accepted'])->assertOk();
        $order = $this->postJson("/api/v1/sales-documents/{$quote['id']}/convert")->assertCreated()->json('data');
        $this->assertSame('open', $order['status']);
        $lineId = $order['lines'][0]['id'];

        $first = $this->postJson("/api/v1/sales-documents/{$order['id']}/invoice", ['lines' => [['line_id' => $lineId, 'quantity' => 4]]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertSame('2124.0000', $first['grand_total']);
        $this->assertSame('2124.0000', $first['balance_due']);
        $this->assertSame(today()->addDays(30)->toDateString(), $first['due_date']);
        $this->assertSame('partly_invoiced', DB::table('sales_orders')->where('id', $order['id'])->value('status'));
        $this->assertEquals($stockBefore - 4, (float) DB::table('stock_balances')->where('product_id', $product->id)->where('warehouse_id', $warehouse)->value('on_hand'));

        // Cannot invoice more than is left.
        $this->postJson("/api/v1/sales-documents/{$order['id']}/invoice", ['lines' => [['line_id' => $lineId, 'quantity' => 7]]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);

        // A receipt pays the oldest invoice first.
        $this->postJson("/api/v1/customers/{$customer->id}/payments", ['company_id' => DB::table('companies')->value('id'), 'method' => 'bank', 'amount' => 1000],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('1124.0000', DB::table('sales')->where('id', $first['id'])->value('balance_due'));

        // A paid-into invoice cannot be voided.
        $this->postJson("/api/v1/sales/{$first['id']}/void", ['reason' => 'test'])->assertStatus(422);

        $this->postJson("/api/v1/sales-documents/{$order['id']}/invoice", [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('invoiced', DB::table('sales_orders')->where('id', $order['id'])->value('status'));

        $aging = collect($this->getJson('/api/v1/receivables/aging')->assertOk()->json('data'))->firstWhere('customer_id', $customer->id);
        $this->assertSame('4310.00', $aging['current']); // 5,310 − 1,000, nothing overdue yet

        $statement = $this->getJson("/api/v1/customers/{$customer->id}/statement?from=".today()->toDateString().'&to='.today()->toDateString())->assertOk()->json('data');
        $this->assertSame('4310.00', $statement['closing']);
    }
}
