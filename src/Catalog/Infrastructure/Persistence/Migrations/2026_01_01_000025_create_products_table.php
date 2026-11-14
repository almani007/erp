<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('sku', 80);
            $table->string('name', 200);
            $table->string('product_type', 30);
            $table->uuid('base_unit_id');
            $table->uuid('category_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->uuid('tax_code_id')->nullable();
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_serial')->default(false);
            $table->boolean('track_expiry')->default(false);
            // Default retail price and standard cost in the company base currency.
            // Price lists (product_prices) refine these per company/customer group.
            $table->decimal('sale_price', 19, 4)->default(0);
            $table->decimal('cost_price', 19, 4)->default(0);
            $table->decimal('reorder_level', 20, 6)->default(0);
            $table->string('status', 20)->default('active');
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('base_unit_id')->references('id')->on('units');
            $table->foreign('category_id')->references('id')->on('product_categories');
            $table->foreign('brand_id')->references('id')->on('product_brands');
            $table->foreign('tax_code_id')->references('id')->on('tax_codes');
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
