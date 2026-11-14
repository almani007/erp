<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Settings-driven record types, business templates and SaaS plans. Needs `erp_test` (see SaleFlowTest). */
final class SaasAndRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_a_business_defines_a_record_type_and_keeps_records_in_it(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());

        $this->postJson('/api/v1/record-types', [
            'name' => 'Job card', 'number_prefix' => 'JOB', 'statuses' => 'Open, Done',
            'fields' => [
                ['label' => 'Device', 'type' => 'text', 'required' => true],
                ['label' => 'Estimate', 'type' => 'money'],
                ['label' => 'Priority', 'type' => 'select', 'options' => 'Low, High'],
            ],
        ])->assertCreated();

        $record = $this->postJson('/api/v1/records/job_card', ['data' => ['device' => 'Phone', 'estimate' => '1500', 'priority' => 'High', 'junk' => 'x']])
            ->assertCreated()->json('data');

        $this->assertSame('Open', $record['status']);
        $this->assertArrayNotHasKey('junk', $record['data']);
        $this->assertStringStartsWith('JOB', $record['record_no']);

        $this->postJson('/api/v1/records/job_card', ['data' => ['estimate' => '10']])->assertStatus(422); // device required
        $this->postJson('/api/v1/records/job_card', ['data' => ['device' => 'A', 'priority' => 'Urgent']])->assertStatus(422);

        $this->putJson("/api/v1/records/job_card/{$record['id']}", ['status' => 'Done', 'data' => ['estimate' => '1800']])
            ->assertOk()->assertJsonPath('data.data.device', 'Phone');
    }

    public function test_the_platform_admin_provisions_a_restaurant_with_a_limited_plan(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'platform@brixelltd.com')->firstOrFail());

        $this->postJson('/api/v1/saas/tenants', [
            'slug' => 'cafe-one', 'legal_name' => 'Cafe One', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi', 'template' => 'restaurant',
            'owner_name' => 'Owner', 'owner_email' => 'owner@cafe.test', 'owner_password' => 'CafeOwner2026!',
            'modules' => ['purchasing' => false], 'max_users' => 1,
        ], ['Idempotency-Key' => 'provision-cafe-one'])->assertCreated();

        $owner = User::query()->where('email', 'owner@cafe.test')->firstOrFail();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/record-types')->assertOk()->assertJsonFragment(['key' => 'table_reservation']);
        $this->getJson('/api/v1/suppliers')->assertForbidden();                 // module not in plan
        $this->getJson('/api/v1/app-config')->assertOk()->assertJsonPath('data.business_type', 'restaurant');
        $this->postJson('/api/v1/users', ['name' => 'B', 'email' => 'b@cafe.test', 'password' => 'Another2026!x'])->assertStatus(422); // max 1 user
        $this->assertSame(1, DB::table('posting_accounts')->where('tenant_id', $owner->tenant_id)->where('key', 'cash')->count());
    }

    public function test_tenant_users_cannot_reach_the_platform_console(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());

        $this->getJson('/api/v1/saas/tenants')->assertForbidden();
    }
}
