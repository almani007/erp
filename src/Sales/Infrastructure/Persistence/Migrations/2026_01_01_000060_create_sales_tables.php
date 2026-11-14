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
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('customer_code', 30);
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('tax_no', 80)->nullable();
            $table->decimal('credit_limit', 19, 4)->default(0);
            $table->decimal('balance', 19, 4)->default(0); // amount the customer owes
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'customer_code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('pos_terminals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id'); // stock is sold from here
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('terminal_id');
            $table->uuid('cashier_id');
            $table->string('shift_no', 50);
            $table->string('status', 20)->default('open');
            $table->decimal('opening_cash', 19, 4)->default(0);
            $table->decimal('expected_cash', 19, 4)->nullable();
            $table->decimal('counted_cash', 19, 4)->nullable();
            $table->decimal('variance', 19, 4)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('terminal_id')->references('id')->on('pos_terminals');
            $table->foreign('cashier_id')->references('id')->on('users');
            $table->unique(['branch_id', 'shift_no']);
        });

        // At most one open shift per terminal and per cashier, guaranteed by the database.
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX pos_shifts_one_open_per_terminal ON pos_shifts (terminal_id) WHERE status = 'open';
            CREATE UNIQUE INDEX pos_shifts_one_open_per_cashier ON pos_shifts (cashier_id) WHERE status = 'open';
        SQL);

        Schema::create('pos_cash_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shift_id');
            $table->string('type', 20); // paid_in | paid_out
            $table->decimal('amount', 19, 4);
            $table->string('reason', 255);
            $table->uuid('created_by');
            $table->timestampTz('created_at');

            $table->foreign('shift_id')->references('id')->on('pos_shifts');
        });

        Schema::create('sales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->uuid('terminal_id');
            $table->uuid('shift_id');
            $table->uuid('cashier_id');
            $table->uuid('customer_id')->nullable();
            $table->string('sale_no', 50);
            $table->string('status', 20); // completed | voided
            $table->char('currency', 3);
            $table->decimal('subtotal', 19, 4);
            $table->decimal('discount_total', 19, 4);
            $table->decimal('tax_total', 19, 4);
            $table->decimal('grand_total', 19, 4);
            $table->decimal('paid_total', 19, 4);
            $table->decimal('change_total', 19, 4);
            $table->decimal('cost_total', 19, 4);
            $table->uuid('journal_entry_id')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestampTz('sold_at');
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shift_id')->references('id')->on('pos_shifts');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->unique(['company_id', 'branch_id', 'sale_no']);
            $table->unique(['tenant_id', 'cashier_id', 'idempotency_key']);
            $table->index(['tenant_id', 'branch_id', 'sold_at']);
            $table->index('shift_id');
        });

        Schema::create('sale_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('sale_id');
            $table->uuid('product_id');
            $table->string('sku', 80);          // snapshots: the receipt must not change
            $table->string('product_name', 200); // when the product is renamed later
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('discount_amount', 19, 4);
            $table->decimal('tax_rate', 8, 4);
            $table->decimal('tax_amount', 19, 4);
            $table->decimal('net_amount', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->decimal('cost_snapshot', 19, 4); // unit cost at the moment of sale

            $table->foreign('sale_id')->references('id')->on('sales');
            $table->foreign('product_id')->references('id')->on('products');
            $table->index('sale_id');
        });

        Schema::create('sale_tenders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('sale_id');
            $table->string('method', 20);
            $table->decimal('amount', 19, 4);
            // Card/wallet: the provider's reference only. Card numbers are never stored.
            $table->string('external_reference', 120)->nullable();

            $table->foreign('sale_id')->references('id')->on('sales');
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_tenders');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_shifts');
        Schema::dropIfExists('pos_terminals');
        Schema::dropIfExists('customers');
    }
};
