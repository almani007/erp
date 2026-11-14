<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->string('status', 20)->default('active');
            // true → may work in every branch of the tenant; false → only branch_user rows.
            $table->boolean('all_branches')->default(false);
            $table->boolean('mfa_enabled')->default(false);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('branch_user', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->uuid('branch_id');

            $table->primary(['user_id', 'branch_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('users');
    }
};
