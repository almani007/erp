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
        // Current position per product and warehouse — a projection of stock_movements.
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->decimal('on_hand', 20, 6)->default(0);
            $table->decimal('reserved', 20, 6)->default(0);
            $table->decimal('avg_cost', 19, 4)->default(0); // weighted average
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('product_id')->references('id')->on('products');
            $table->unique(['warehouse_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
        });

        // The stock ledger: one immutable row per change, always tied to a source document.
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->string('movement_type', 30);
            $table->decimal('qty_delta', 20, 6);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('balance_after', 20, 6);
            $table->string('source_type', 60);
            $table->uuid('source_id');
            $table->string('note', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('occurred_at');

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('product_id')->references('id')->on('products');
            $table->index(['warehouse_id', 'product_id', 'id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->string('adjustment_no', 50);
            $table->string('reason_code', 40); // opening_balance | count_variance | damage | expiry | other
            $table->string('notes', 500)->nullable();
            $table->decimal('total_value', 19, 4)->default(0);
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('posted_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->unique(['company_id', 'adjustment_no']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('stock_adjustment_id');
            $table->uuid('product_id');
            $table->decimal('qty_delta', 20, 6);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('value', 19, 4);

            $table->foreign('stock_adjustment_id')->references('id')->on('stock_adjustments');
            $table->foreign('product_id')->references('id')->on('products');
        });

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('transfer_no', 50);
            $table->uuid('source_warehouse_id');
            $table->uuid('destination_warehouse_id');
            $table->string('status', 20); // in_transit | received
            $table->string('notes', 500)->nullable();
            $table->uuid('dispatched_by')->nullable();
            $table->timestampTz('dispatched_at');
            $table->uuid('received_by')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('source_warehouse_id')->references('id')->on('warehouses');
            $table->foreign('destination_warehouse_id')->references('id')->on('warehouses');
            $table->unique(['company_id', 'transfer_no']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('stock_transfer_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 19, 4);

            $table->foreign('stock_transfer_id')->references('id')->on('stock_transfers');
            $table->foreign('product_id')->references('id')->on('products');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_movements_append_only
                BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION erp_forbid_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
    }
};
