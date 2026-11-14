<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->string('count_no', 50);
            $table->string('status', 20); // in_progress | posted | cancelled
            $table->string('notes', 500)->nullable();
            $table->uuid('stock_adjustment_id')->nullable();
            $table->uuid('started_by');
            $table->timestampTz('started_at');
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->unique(['company_id', 'count_no']);
        });

        // Only one count may be open per warehouse at a time.
        DB::unprepared("CREATE UNIQUE INDEX stock_counts_one_open_per_warehouse ON stock_counts (warehouse_id) WHERE status = 'in_progress'");

        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('stock_count_id');
            $table->uuid('product_id');
            $table->decimal('system_qty', 20, 6);            // on hand when the count started
            $table->decimal('counted_qty', 20, 6)->nullable(); // null = not counted yet
            $table->decimal('variance_qty', 20, 6)->nullable(); // set when posted

            $table->foreign('stock_count_id')->references('id')->on('stock_counts');
            $table->foreign('product_id')->references('id')->on('products');
            $table->unique(['stock_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
