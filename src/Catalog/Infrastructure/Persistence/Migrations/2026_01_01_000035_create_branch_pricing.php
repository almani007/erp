<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Branch-wise price: overrides the product's default sale price in one branch.
        Schema::create('branch_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('product_id');
            $table->decimal('price', 19, 4);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('product_id')->references('id')->on('products');
            $table->unique(['branch_id', 'product_id']);
        });

        Schema::table('promotion_rules', function (Blueprint $table): void {
            $table->uuid('branch_id')->nullable();           // null = every branch of the company
            $table->boolean('requires_coupon')->default(false); // true = only with a coupon code

            $table->foreign('branch_id')->references('id')->on('branches');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_rules', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['branch_id', 'requires_coupon']);
        });
        Schema::dropIfExists('branch_prices');
    }
};
