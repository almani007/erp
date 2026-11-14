<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('supplier_code', 30);
            $table->string('name', 150);
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('tax_no', 80)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->decimal('balance', 19, 4)->default(0); // amount we owe the supplier
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'supplier_code']);
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->uuid('supplier_id');
            $table->string('po_no', 50);
            $table->string('status', 30); // draft | approved | partially_received | received | cancelled
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('grand_total', 19, 4)->default(0);
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->unique(['company_id', 'po_no']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('purchase_order_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('received_quantity', 20, 6)->default(0);

            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders');
            $table->foreign('product_id')->references('id')->on('products');
            $table->index('purchase_order_id');
        });

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->uuid('supplier_id');
            $table->uuid('purchase_order_id');
            $table->string('grn_no', 50);
            $table->string('supplier_invoice_no', 80)->nullable();
            $table->decimal('subtotal', 19, 4);
            $table->decimal('tax_total', 19, 4);
            $table->decimal('grand_total', 19, 4);
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('received_by');
            $table->timestampTz('received_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->unique(['company_id', 'grn_no']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('goods_receipt_id');
            $table->uuid('purchase_order_item_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('tax_amount', 19, 4);
            $table->decimal('line_total', 19, 4);

            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts');
            $table->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items');
        });

        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('supplier_id');
            $table->string('payment_no', 50);
            $table->string('method', 20); // cash | bank
            $table->decimal('amount', 19, 4);
            $table->string('reference', 120)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by');
            $table->timestampTz('paid_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->unique(['company_id', 'payment_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
