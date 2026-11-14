<?php

declare(strict_types=1);

namespace Database\Seeders;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\Tenant;
use Saas\Infrastructure\Persistence\Eloquent\BusinessTemplate;

/**
 * The platform operator's side: the starter business templates and the
 * platform tenant with its administrator. Safe to run again — existing
 * templates are not overwritten, so edits made in the SaaS admin survive.
 */
final class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('business_templates') as $template) {
            BusinessTemplate::query()->firstOrCreate(['code' => $template['code']], [
                'name' => $template['name'],
                'description' => $template['description'] ?? null,
                'features' => $template['features'],
                'custom_fields' => $template['custom_fields'] ?? null,
                'record_types' => $template['record_types'] ?? null,
                'status' => 'active',
            ]);
        }

        $email = mb_strtolower((string) env('ERP_PLATFORM_ADMIN_EMAIL', 'platform@brixelltd.com'));
        $password = env('ERP_PLATFORM_ADMIN_PASSWORD') ?: (app()->environment('production') ? null : 'BrixelPlatform2026!');

        if ($password === null) {
            $this->command?->warn('ERP_PLATFORM_ADMIN_PASSWORD is not set: no platform administrator was created.');

            return;
        }

        $tenant = Tenant::query()->firstOrCreate(['slug' => 'platform'], [
            'legal_name' => 'Platform administration',
            'status' => 'active',
            'default_timezone' => 'Asia/Karachi',
            'default_currency' => 'PKR',
        ]);

        if (DB::table('users')->where('email', $email)->exists()) {
            return;
        }

        $user = new User(['name' => 'Platform Admin', 'email' => $email, 'password' => $password, 'status' => 'active', 'all_branches' => true]);
        $user->tenant_id = $tenant->id;
        $user->forceFill(['is_platform_admin' => true])->save();
    }
}
