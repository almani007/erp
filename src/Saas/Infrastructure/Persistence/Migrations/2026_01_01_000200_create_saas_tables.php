<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // What the platform operator allows this tenant to use:
            // {"modules": {"purchasing": false}, "features": {"rates": false}, "max_users": 5, "max_branches": 2}
            // A missing key means "allowed" / "no limit".
            $table->jsonb('entitlements')->nullable();
            $table->string('business_template', 40)->nullable(); // template last applied
        });

        Schema::table('users', function (Blueprint $table): void {
            // Platform operators manage tenants. Never settable through the API.
            $table->boolean('is_platform_admin')->default(false);
        });

        // Platform-wide (not tenant-owned): what a kind of business starts with.
        Schema::create('business_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->jsonb('features');                 // {"wholesale": true, "expiry": false, …}
            $table->jsonb('custom_fields')->nullable(); // [{entity, label, type, options}]
            $table->jsonb('record_types')->nullable();  // [{name, plural, prefix, statuses, link_customer, fields: [...]}]
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_templates');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_admin'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['entitlements', 'business_template']));
    }
};
