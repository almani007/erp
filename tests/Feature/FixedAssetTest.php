<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Register → monthly depreciation → reverse → dispose. Needs `erp_test` (see SaleFlowTest). */
final class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_asset_lifecycle_with_balanced_journals(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $category = DB::table('asset_categories')->where('name', 'Furniture & fixtures')->value('id');
        $period = now()->format('Y-m');

        $asset = $this->postJson('/api/v1/fixed-assets', [
            'category_id' => $category, 'name' => 'Cold room', 'purchase_date' => now()->startOfMonth()->toDateString(),
            'cost' => 120000, 'useful_life_months' => 12, 'method' => 'straight_line', 'funding' => 'bank',
        ], $key())->assertCreated()->json('data');

        $preview = collect($this->getJson("/api/v1/depreciation-runs/preview?period={$period}")->assertOk()->json('data'))->firstWhere('asset_id', $asset['id']);
        $this->assertSame('10000.00', $preview['amount']); // 120,000 over 12 months

        $run = $this->postJson('/api/v1/depreciation-runs', ['period' => $period], $key())->assertCreated()->json('data');
        $this->assertSame('10000.0000', DB::table('fixed_assets')->where('id', $asset['id'])->value('accumulated_depreciation'));
        $sums = DB::table('journal_lines')->where('journal_entry_id', $run['journal_entry_id'])->selectRaw('sum(debit) d, sum(credit) c')->first();
        $this->assertSame($sums->d, $sums->c);

        // The same month cannot be charged twice.
        $this->postJson('/api/v1/depreciation-runs', ['period' => $period], $key())->assertStatus(422);

        $this->postJson("/api/v1/depreciation-runs/{$run['id']}/reverse", ['reason' => 'Wrong useful life'])->assertOk();
        $this->assertSame('0.0000', DB::table('fixed_assets')->where('id', $asset['id'])->value('accumulated_depreciation'));

        // Sold for 115,000 with nothing depreciated: a 5,000 loss.
        $this->postJson("/api/v1/fixed-assets/{$asset['id']}/dispose", ['date' => today()->toDateString(), 'proceeds' => 115000, 'received_by' => 'bank'], $key())
            ->assertOk()->assertJsonPath('data.status', 'disposed')->assertJsonPath('data.disposal_gain_loss', '-5000.00');
    }
}
