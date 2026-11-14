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
        // Stored-value gift cards and vouchers. The unspent value is a liability until redeemed.
        Schema::create('gift_cards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('code', 40);
            $table->string('kind', 20)->default('gift_card');   // gift_card | voucher (credit note / promotional)
            $table->decimal('initial_value', 19, 4);
            $table->decimal('balance', 19, 4);
            $table->uuid('customer_id')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status', 20);                         // active | blocked | used | expired
            $table->string('paid_by', 20)->nullable();            // cash | bank | promotion
            $table->string('note', 300)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('issued_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('gift_card_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('gift_card_id');
            $table->string('kind', 20);                  // issue | redeem | reverse | expire | adjust
            $table->decimal('amount', 19, 4);            // + adds value, − spends it
            $table->decimal('balance_after', 19, 4);
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('gift_card_id')->references('id')->on('gift_cards')->cascadeOnDelete();
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->string('voucher_code', 40)->nullable(); // store credit issued instead of a cash refund
        });

        $accounts = [
            ['2410', 'Gift Cards & Vouchers Outstanding', 'liability', 'gift_card_liability'],
            ['4960', 'Gift Card Breakage (expired value)', 'revenue', 'gift_card_breakage'],
            ['5140', 'Promotional Vouchers', 'expense', 'promotional_vouchers'],
        ];
        foreach (DB::table('companies')->get(['id', 'tenant_id']) as $company) {
            foreach ($accounts as [$code, $name, $type, $key]) {
                if (DB::table('posting_accounts')->where('company_id', $company->id)->where('key', $key)->exists()) {
                    continue;
                }
                $accountId = DB::table('chart_of_accounts')->where('company_id', $company->id)->where('code', $code)->value('id');
                if ($accountId === null) {
                    $accountId = (string) Str::uuid7();
                    DB::table('chart_of_accounts')->insert([
                        'id' => $accountId, 'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'code' => $code,
                        'name' => $name, 'account_type' => $type, 'is_system' => true, 'status' => 'active',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('posting_accounts')->insert(['tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'key' => $key, 'account_id' => $accountId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sales_returns', fn (Blueprint $table) => $table->dropColumn('voucher_code'));
        Schema::dropIfExists('gift_card_transactions');
        Schema::dropIfExists('gift_cards');
    }
};
