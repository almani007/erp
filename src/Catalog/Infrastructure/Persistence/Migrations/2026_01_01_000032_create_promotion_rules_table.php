<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('name', 150);
            $table->string('type', 30); // percentage, fixed, buy_x_get_y, bundle
            $table->decimal('value', 19, 4)->nullable();
            $table->jsonb('conditions')->nullable();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->index(['tenant_id', 'company_id', 'status', 'start_at', 'end_at'], 'promo_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_rules');
    }
};
