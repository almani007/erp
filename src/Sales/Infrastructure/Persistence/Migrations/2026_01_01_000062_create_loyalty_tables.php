<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->bigInteger('loyalty_points')->default(0);
            $table->string('price_tier', 20)->default('retail'); // retail | wholesale
        });

        // Lines sold in a pack: quantity is in packs, unit_factor converts to base units for stock.
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('unit_name', 50)->nullable();
            $table->decimal('unit_factor', 20, 6)->default(1);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->bigInteger('points_earned')->default(0);
            $table->bigInteger('points_redeemed')->default(0);
            $table->string('price_tier', 20)->default('retail'); // tier the sale was priced at
        });

        // Points ledger: the customer's balance is always the sum of these rows.
        Schema::create('loyalty_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id');
            $table->string('type', 20); // earn | redeem | reverse | adjust
            $table->bigInteger('points'); // signed
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['points_earned', 'points_redeemed', 'price_tier']));
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['unit_name', 'unit_factor']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['loyalty_points', 'price_tier']));
    }
};
