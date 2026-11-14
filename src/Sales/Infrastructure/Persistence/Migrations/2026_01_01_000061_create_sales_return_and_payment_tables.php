<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('coupon_code', 50)->nullable();
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('promotion_name', 150)->nullable(); // the deal applied to this line, if any
        });

        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->uuid('sale_id');
            $table->uuid('shift_id')->nullable(); // set for cash refunds: the cash leaves this drawer
            $table->string('return_no', 50);
            $table->string('reason', 300);
            $table->string('refund_method', 20); // cash | bank | account
            $table->decimal('net_total', 19, 4);
            $table->decimal('tax_total', 19, 4);
            $table->decimal('grand_total', 19, 4);
            $table->decimal('cost_total', 19, 4);
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by');
            $table->timestampTz('returned_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->unique(['company_id', 'branch_id', 'return_no']);
            $table->index('sale_id');
            $table->index('shift_id');
        });

        Schema::create('sales_return_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('sales_return_id');
            $table->uuid('sale_item_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 20, 6);
            $table->decimal('net_amount', 19, 4);
            $table->decimal('tax_amount', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->boolean('restocked');

            $table->foreign('sales_return_id')->references('id')->on('sales_returns');
            $table->foreign('sale_item_id')->references('id')->on('sale_items');
            $table->index('sale_item_id');
        });

        Schema::create('customer_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('customer_id');
            $table->string('receipt_no', 50);
            $table->string('method', 20); // cash | bank
            $table->decimal('amount', 19, 4);
            $table->string('reference', 120)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by');
            $table->timestampTz('received_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->unique(['company_id', 'receipt_no']);
            $table->index(['customer_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('promotion_name'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('coupon_code'));
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
    }
};
