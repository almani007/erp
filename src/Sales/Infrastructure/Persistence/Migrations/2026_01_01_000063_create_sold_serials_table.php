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
            $table->jsonb('custom')->nullable();
        });

        // Serial / IMEI numbers captured at the till for products that track
        // them. Answers "when was this unit sold, to whom, is it in warranty?"
        Schema::create('sold_serials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('serial', 100);
            $table->uuid('sale_id');
            $table->uuid('sale_item_id');
            $table->uuid('customer_id')->nullable();
            $table->timestampTz('sold_at');
            $table->date('warranty_until')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('sale_id')->references('id')->on('sales');
            // A unit can only be out with one customer at a time.
            $table->unique(['tenant_id', 'product_id', 'serial']);
            $table->index(['tenant_id', 'serial']);
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sold_serials');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('custom'));
    }
};
