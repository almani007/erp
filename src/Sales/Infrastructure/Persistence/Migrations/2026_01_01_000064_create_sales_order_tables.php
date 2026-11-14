<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quotations and sales orders share one table: a quotation is
        // converted into an order, an order is invoiced in one or more goes.
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->uuid('customer_id');
            $table->string('doc_type', 20);              // quotation | order
            $table->string('doc_no', 50);
            // quotation: draft | sent | accepted | rejected | converted
            // order:     open | partly_invoiced | invoiced | cancelled
            $table->string('status', 20);
            $table->date('doc_date');
            $table->date('valid_until')->nullable();     // quotation
            $table->date('delivery_date')->nullable();   // order
            $table->unsignedSmallInteger('payment_days')->default(0); // invoice due date = invoice date + days
            $table->string('price_tier', 20)->nullable();
            $table->string('reference', 120)->nullable(); // customer's PO number
            $table->string('notes', 1000)->nullable();
            $table->string('terms', 2000)->nullable();
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('grand_total', 19, 4)->default(0);
            $table->uuid('source_id')->nullable();       // the quotation an order came from
            $table->uuid('salesperson_id')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->unique(['company_id', 'doc_type', 'doc_no']);
            $table->index(['tenant_id', 'doc_type', 'status']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('sales_order_id');
            $table->unsignedSmallInteger('line_no');
            $table->uuid('product_id');
            $table->string('description', 300)->nullable();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 19, 4);        // agreed price, tax as the product's tax code says
            $table->decimal('discount', 19, 4)->default(0); // per line, money
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);
            $table->decimal('invoiced_quantity', 20, 6)->default(0);

            $table->foreign('sales_order_id')->references('id')->on('sales_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('address', 300)->nullable();
            $table->unsignedSmallInteger('payment_days')->default(0); // usual credit terms
        });

        Schema::table('sales', function (Blueprint $table): void {
            // Back-office invoices have no terminal or shift.
            $table->uuid('terminal_id')->nullable()->change();
            $table->uuid('shift_id')->nullable()->change();
            $table->string('channel', 20)->default('pos');   // pos | invoice
            $table->date('due_date')->nullable();
            $table->uuid('sales_order_id')->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('notes', 1000)->nullable();
            // The part of this sale still owed on account: credit tender, less
            // payments applied to it and returns credited to the account.
            $table->decimal('balance_due', 19, 4)->default(0);
            $table->index(['tenant_id', 'customer_id', 'sold_at']);
        });

        // Which invoices each customer receipt paid off.
        Schema::create('customer_payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_payment_id');
            $table->uuid('sale_id');
            $table->decimal('amount', 19, 4);

            $table->foreign('customer_payment_id')->references('id')->on('customer_payments')->cascadeOnDelete();
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'customer_id', 'sold_at']);
            $table->dropColumn(['channel', 'due_date', 'sales_order_id', 'reference', 'notes', 'balance_due']);
        });
        Schema::dropIfExists('customer_payment_allocations');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['address', 'payment_days']));
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
