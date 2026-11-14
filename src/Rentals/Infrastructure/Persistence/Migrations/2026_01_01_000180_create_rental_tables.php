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
        Schema::create('rental_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');                 // where the rented item comes from and goes back to
            $table->string('contract_no', 40);
            $table->uuid('customer_id');
            $table->uuid('rent_product_id');              // service item invoiced each period ("Generator rent")
            $table->uuid('item_product_id')->nullable();  // the stocked item handed over, if tracked in stock
            $table->string('item_description', 200);
            $table->string('serial_no', 100)->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->date('start_date');
            $table->date('end_date')->nullable();         // null = until returned
            $table->string('billing_frequency', 10);      // daily | weekly | monthly
            $table->decimal('rent_amount', 19, 4);        // per period
            $table->date('next_bill_date');
            $table->unsignedSmallInteger('payment_days')->default(0);
            $table->decimal('deposit_amount', 19, 4)->default(0);
            $table->string('deposit_method', 10)->nullable();
            $table->decimal('deposit_refunded', 19, 4)->nullable();
            $table->decimal('deposit_deducted', 19, 4)->nullable();
            $table->string('status', 20);                 // active | returned
            $table->date('returned_on')->nullable();
            $table->string('return_notes', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedInteger('invoices_count')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->unique(['company_id', 'contract_no']);
            $table->index(['status', 'next_bill_date']);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->uuid('rental_contract_id')->nullable();
        });

        // Security deposits held for customers are owed back: a liability.
        foreach (DB::table('companies')->get(['id', 'tenant_id']) as $company) {
            if (DB::table('posting_accounts')->where('company_id', $company->id)->where('key', 'customer_deposits')->exists()) {
                continue;
            }
            $accountId = DB::table('chart_of_accounts')->where('company_id', $company->id)->where('code', '2400')->value('id');
            if ($accountId === null) {
                $accountId = (string) Str::uuid7();
                DB::table('chart_of_accounts')->insert([
                    'id' => $accountId, 'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'code' => '2400',
                    'name' => 'Customer Deposits Held', 'account_type' => 'liability', 'is_system' => true, 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('posting_accounts')->insert(['tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'key' => 'customer_deposits', 'account_id' => $accountId]);
        }
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('rental_contract_id'));
        Schema::dropIfExists('rental_contracts');
    }
};
