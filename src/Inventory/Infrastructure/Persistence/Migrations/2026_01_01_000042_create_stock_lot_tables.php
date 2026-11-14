<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Batch / expiry sub-ledger for products that track them. The stock
        // balance stays the total; lots say which batches it is made of.
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->string('batch_no', 60);
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 20, 6)->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('product_id')->references('id')->on('products');
            $table->unique(['warehouse_id', 'product_id', 'batch_no']);
            $table->index(['tenant_id', 'expiry_date']);
        });

        // Which lots each outgoing document took from, so a void, return or
        // transfer receipt can put stock back into the right batch.
        Schema::create('stock_lot_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('lot_id');
            $table->uuid('product_id');
            $table->string('source_type', 60);
            $table->uuid('source_id');
            $table->decimal('quantity', 20, 6);
            $table->timestampTz('created_at');

            $table->foreign('lot_id')->references('id')->on('stock_lots');
            $table->index(['source_type', 'source_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_lot_allocations');
        Schema::dropIfExists('stock_lots');
    }
};
