<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // The supplier's invoice, matched to the goods receipts it covers
        // (goods bill) or to expense accounts (services, rent, utilities).
        Schema::create('supplier_bills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('supplier_id');
            $table->string('bill_no', 50);                  // ours
            $table->string('supplier_invoice_no', 80);      // theirs
            $table->date('bill_date');
            $table->date('due_date');
            $table->decimal('received_value', 19, 4)->default(0); // goods receipts matched, less returns
            $table->decimal('variance', 19, 4)->default(0);       // their invoice − what we received
            $table->decimal('expense_total', 19, 4)->default(0);
            $table->decimal('expense_tax', 19, 4)->default(0);
            $table->decimal('grand_total', 19, 4);
            $table->decimal('balance_due', 19, 4);
            $table->string('status', 20);                   // open | paid
            $table->string('notes', 1000)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->unique(['company_id', 'bill_no']);
            // The same supplier invoice cannot be entered twice.
            $table->unique(['supplier_id', 'supplier_invoice_no']);
        });

        Schema::create('supplier_bill_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('supplier_bill_id');
            $table->uuid('account_id');
            $table->string('description', 300);
            $table->decimal('amount', 19, 4);
            $table->decimal('tax_amount', 19, 4)->default(0);

            $table->foreign('supplier_bill_id')->references('id')->on('supplier_bills')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('chart_of_accounts');
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->uuid('supplier_bill_id')->nullable();
            $table->decimal('returned_total', 19, 4)->default(0);
        });
        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->decimal('returned_quantity', 20, 6)->default(0);
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('address', 300)->nullable();
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('supplier_payment_id');
            $table->uuid('supplier_bill_id');
            $table->decimal('amount', 19, 4);

            $table->foreign('supplier_payment_id')->references('id')->on('supplier_payments')->cascadeOnDelete();
            $table->foreign('supplier_bill_id')->references('id')->on('supplier_bills');
            $table->index('supplier_bill_id');
        });

        // Debit note: goods sent back to the supplier from a goods receipt.
        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->uuid('supplier_id');
            $table->uuid('goods_receipt_id');
            $table->string('return_no', 50);
            $table->string('reason', 300);
            $table->decimal('subtotal', 19, 4);
            $table->decimal('tax_total', 19, 4);
            $table->decimal('grand_total', 19, 4);
            $table->decimal('stock_cost', 19, 4);            // value taken out of inventory
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by');
            $table->timestampTz('returned_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts');
            $table->unique(['company_id', 'return_no']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('purchase_return_id');
            $table->uuid('goods_receipt_item_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('tax_amount', 19, 4);
            $table->decimal('line_total', 19, 4);

            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->cascadeOnDelete();
        });

        // Price differences between supplier invoices and receipts, and on returns.
        foreach (DB::table('companies')->get(['id', 'tenant_id']) as $company) {
            if (DB::table('posting_accounts')->where('company_id', $company->id)->where('key', 'purchase_price_variance')->exists()) {
                continue;
            }
            $accountId = DB::table('chart_of_accounts')->where('company_id', $company->id)->where('code', '5130')->value('id');
            if ($accountId === null) {
                $accountId = (string) Str::uuid7();
                DB::table('chart_of_accounts')->insert([
                    'id' => $accountId, 'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'code' => '5130',
                    'name' => 'Purchase Price Variance', 'account_type' => 'expense', 'is_system' => true, 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('posting_accounts')->insert(['tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'key' => 'purchase_price_variance', 'account_id' => $accountId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('address'));
        Schema::table('goods_receipt_items', fn (Blueprint $table) => $table->dropColumn('returned_quantity'));
        Schema::table('goods_receipts', fn (Blueprint $table) => $table->dropColumn(['supplier_bill_id', 'returned_total']));
        Schema::dropIfExists('supplier_bill_lines');
        Schema::dropIfExists('supplier_bills');
    }
};
