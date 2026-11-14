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
        // Foreign currencies the business deals in (the company's base currency is not listed).
        Schema::create('currencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->char('code', 3);
            $table->string('name', 60);
            $table->string('symbol', 8)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'code']);
        });

        // How many units of the company's base currency one unit of the currency buys, by date.
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->char('currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 19, 8);
            $table->string('source', 40)->nullable();      // manual | bank | sbp …
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['company_id', 'currency', 'rate_date']);
        });

        // Month-end revaluation of open foreign balances, reversed the next day.
        Schema::create('fx_revaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->date('as_of');
            $table->decimal('net_adjustment', 19, 4);      // + = loss (more owed), − = gain
            $table->jsonb('lines');                        // per document: currency, open foreign, carried, revalued, difference
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('reversal_journal_entry_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['company_id', 'as_of']);
        });

        $accounts = [
            ['4970', 'Exchange Gain / Loss — Realised', 'revenue', 'fx_realized'],
            ['4975', 'Exchange Gain / Loss — Unrealised', 'revenue', 'fx_unrealized'],
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
        Schema::dropIfExists('fx_revaluations');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
