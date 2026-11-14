<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Integration\Application\IntegrationRelay;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** FBR reporting, webhooks, imports and API keys. External calls are faked. Needs `erp_test` (see SaleFlowTest). */
final class IntegrationHubTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_pos_sale_is_reported_to_fbr_and_webhook_is_signed(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $branch = DB::table('branches')->where('code', 'HO')->value('id');
        $h = fn () => ['X-Branch-ID' => $branch, 'Idempotency-Key' => (string) Str::uuid()];

        $this->putJson('/api/v1/integrations/fbr/settings', ['branch_id' => $branch, 'pos_enabled' => true, 'pos_environment' => 'sandbox', 'pos_id' => '123456', 'pos_token' => 'test-token'])->assertOk();
        $hook = $this->postJson('/api/v1/integrations/webhooks', ['name' => 'Shop site', 'url' => 'https://shop.example.com/erp-hook', 'events' => ['sales.sale.completed']])
            ->assertCreated()->json('data');

        Http::fake([
            'esp.fbr.gov.pk*' => Http::response(['InvoiceNumber' => '123456ABCDEF', 'Code' => '100', 'Response' => 'Fiscal Invoice Number generated successfully.']),
            'shop.example.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/v1/pos/shifts/open', ['terminal_id' => DB::table('pos_terminals')->value('id'), 'opening_cash' => 1000], $h())->assertCreated();
        $sale = $this->postJson('/api/v1/sales', [
            'lines' => [['product_id' => DB::table('products')->where('sku', 'HOU-002')->value('id'), 'quantity' => 1]],
            'tenders' => [['method' => 'cash', 'amount' => 1000]],
        ], $h())->assertCreated()->json('data');

        app(IntegrationRelay::class)->run(); // publish the event: queue FBR and webhook
        app(IntegrationRelay::class)->run(); // nothing new; both already sent on the first run

        $this->assertSame('123456ABCDEF', DB::table('sales')->where('id', $sale['id'])->value('fiscal_invoice_no'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'esp.fbr.gov.pk') && $r['USIN'] === $sale['sale_no'] && $r['POSID'] === 123456 && $r->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://shop.example.com/erp-hook'
            && $r->header('X-ERP-Signature')[0] === 'sha256='.hash_hmac('sha256', $r->body(), $hook['secret']));
        $this->assertSame('delivered', DB::table('webhook_deliveries')->value('status'));
    }

    public function test_csv_import_checks_then_saves_and_api_key_is_issued_once(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $csv = "name,phone,credit_limit,price_tier\nAkbar Store,0300-9999999,25000,wholesale\n,0300-0000000,10,retail\n";
        $file = fn () => UploadedFile::fake()->createWithContent('customers.csv', $csv);

        $this->post('/api/v1/integrations/import/customers', ['file' => $file(), 'dry_run' => true])
            ->assertOk()->assertJsonPath('data.valid', 1)->assertJsonPath('data.saved', 0)->assertJsonPath('data.errors.0.row', 3);
        $this->assertFalse(DB::table('customers')->where('name', 'Akbar Store')->exists());

        $this->post('/api/v1/integrations/import/customers', ['file' => $file(), 'dry_run' => false])->assertOk()->assertJsonPath('data.saved', 1);
        $this->assertTrue(DB::table('customers')->where('name', 'Akbar Store')->where('price_tier', 'wholesale')->exists());

        $key = $this->postJson('/api/v1/integrations/api-clients', ['name' => 'Website', 'role' => 'cashier'])->assertCreated()->json('data.api_key');
        $this->assertStringContainsString('|', $key);
        $this->getJson('/api/v1/integrations/api-clients')->assertOk()->assertJsonMissing(['api_key' => $key]);
    }
}
