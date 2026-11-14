<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** [code, name, type, posting key] */
    public const array ACCOUNTS = [
        ['1500', 'Fixed Assets', 'asset', 'fixed_assets'],
        ['1590', 'Accumulated Depreciation', 'asset', 'accumulated_depreciation'],
        ['4950', 'Gain / Loss on Asset Disposal', 'revenue', 'asset_disposal'],
        ['5600', 'Depreciation Expense', 'expense', 'depreciation_expense'],
    ];

    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 100);
            $table->string('method', 30);                     // straight_line | reducing_balance
            $table->unsignedSmallInteger('useful_life_months')->nullable(); // straight line
            $table->decimal('rate_percent', 7, 3)->nullable();  // reducing balance, per year
            $table->decimal('residual_percent', 7, 3)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('category_id');
            $table->string('asset_no', 50);
            $table->string('name', 200);
            $table->string('serial_no', 100)->nullable();
            $table->string('location', 150)->nullable();
            $table->uuid('custodian_id')->nullable();         // employee responsible
            $table->uuid('supplier_id')->nullable();
            $table->date('purchase_date');
            $table->date('in_service_date');                  // depreciation starts this month
            $table->decimal('cost', 19, 4);
            $table->decimal('residual_value', 19, 4)->default(0);
            $table->string('method', 30);
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->decimal('rate_percent', 7, 3)->nullable();
            $table->decimal('opening_depreciation', 19, 4)->default(0); // before it was entered here
            $table->decimal('accumulated_depreciation', 19, 4)->default(0);
            $table->date('depreciated_to')->nullable();       // last month-end charged
            $table->string('funding', 20);                    // opening | cash | bank | supplier
            $table->string('status', 20)->default('active');  // active | fully_depreciated | disposed
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_proceeds', 19, 4)->nullable();
            $table->decimal('disposal_gain_loss', 19, 4)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->jsonb('custom')->nullable();
            $table->uuid('acquisition_journal_id')->nullable();
            $table->uuid('disposal_journal_id')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('category_id')->references('id')->on('asset_categories');
            $table->unique(['company_id', 'asset_no']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('depreciation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('run_no', 50);
            $table->string('period', 7);                      // 2026-11
            $table->decimal('total', 19, 4);
            $table->unsignedInteger('asset_count');
            $table->string('status', 20);                     // posted | reversed
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['company_id', 'run_no']);
        });
        DB::statement("CREATE UNIQUE INDEX depreciation_runs_one_per_period ON depreciation_runs (company_id, period) WHERE status = 'posted'");

        Schema::create('depreciation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('depreciation_run_id');
            $table->uuid('fixed_asset_id');
            $table->decimal('amount', 19, 4);
            $table->decimal('accumulated_after', 19, 4);
            $table->date('previous_depreciated_to')->nullable();

            $table->foreign('depreciation_run_id')->references('id')->on('depreciation_runs')->cascadeOnDelete();
            $table->foreign('fixed_asset_id')->references('id')->on('fixed_assets');
        });

        foreach (DB::table('companies')->get(['id', 'tenant_id']) as $company) {
            foreach (self::ACCOUNTS as [$code, $name, $type, $key]) {
                if (DB::table('posting_accounts')->where('company_id', $company->id)->where('key', $key)->exists()) {
                    continue;
                }
                $accountId = DB::table('chart_of_accounts')->where('company_id', $company->id)->where('code', $code)->value('id');
                if ($accountId === null) {
                    $accountId = (string) Str::uuid7();
                    DB::table('chart_of_accounts')->insert([
                        'id' => $accountId, 'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'code' => $code,
                        'name' => $name, 'account_type' => $type, 'is_system' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('posting_accounts')->insert(['tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'key' => $key, 'account_id' => $accountId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_lines');
        Schema::dropIfExists('depreciation_runs');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
