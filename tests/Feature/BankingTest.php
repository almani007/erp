<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Cheque clearing, transfers and bank reconciliation. Needs `erp_test` (see SaleFlowTest). */
final class BankingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_cheque_clears_into_bank_and_reconciles(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $customer = DB::table('customers')->where('customer_code', 'CUS-000003')->first();
        $bank = DB::table('bank_accounts')->where('kind', 'bank')->first();
        $cash = DB::table('bank_accounts')->where('kind', 'cash')->first();

        // Put 1,000 on the customer's account through an invoiced order.
        $order = $this->postJson('/api/v1/sales-documents', [
            'doc_type' => 'order', 'warehouse_id' => DB::table('warehouses')->where('code', 'WH-MAIN')->value('id'), 'customer_id' => $customer->id,
            'doc_date' => today()->toDateString(),
            'lines' => [['product_id' => DB::table('products')->where('sku', 'PHA-PARA-500')->value('id'), 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/sales-documents/{$order['id']}/invoice", [], $key())->assertCreated();
        $owed = (float) DB::table('customers')->where('id', $customer->id)->value('balance');

        $cheque = $this->postJson('/api/v1/cheques', [
            'direction' => 'received', 'party_id' => $customer->id, 'cheque_no' => '00451', 'drawer_bank' => 'HBL',
            'cheque_date' => today()->toDateString(), 'amount' => $owed,
        ], $key())->assertCreated()->json('data');
        $this->assertEquals($owed, (float) DB::table('customers')->where('id', $customer->id)->value('balance')); // a cheque in hand changes nothing

        $this->postJson("/api/v1/cheques/{$cheque['id']}/deposit", ['bank_account_id' => $bank->id, 'date' => today()->toDateString()], $key())->assertOk();
        $this->postJson("/api/v1/cheques/{$cheque['id']}/clear", [], $key())->assertOk()->assertJsonPath('data.status', 'cleared');
        $this->assertEquals(0, (float) DB::table('customers')->where('id', $customer->id)->value('balance'));

        $this->postJson('/api/v1/bank-transfers', ['from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'transfer_date' => today()->toDateString(), 'amount' => 500], $key())
            ->assertCreated();

        $balance = collect($this->getJson('/api/v1/bank-accounts')->assertOk()->json('data'))->firstWhere('id', $bank->id)['balance'];
        $work = $this->postJson("/api/v1/bank-accounts/{$bank->id}/reconciliations", ['statement_date' => today()->toDateString(), 'statement_balance' => $balance])
            ->assertOk()->json('data');
        $recId = $work['reconciliation']['id'];

        $this->postJson("/api/v1/bank-reconciliations/{$recId}/complete")->assertStatus(422); // nothing ticked yet
        $this->putJson("/api/v1/bank-reconciliations/{$recId}/lines", ['line_ids' => array_column($work['lines'], 'id')])
            ->assertOk()->assertJsonPath('data.difference', '0.00');
        $this->postJson("/api/v1/bank-reconciliations/{$recId}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    }
}
