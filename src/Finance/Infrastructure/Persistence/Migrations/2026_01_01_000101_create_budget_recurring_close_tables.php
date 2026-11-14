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
        Schema::create('budgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('name', 120);
            $table->date('start_date');                     // first day of month 1 of 12
            $table->string('status', 20)->default('active');
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['company_id', 'name']);
        });

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('budget_id');
            $table->uuid('account_id');
            $table->uuid('branch_id')->nullable();          // null = whole company
            $table->jsonb('amounts');                       // 12 monthly figures, as decimal strings

            $table->foreign('budget_id')->references('id')->on('budgets')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('chart_of_accounts');
        });
        DB::statement("CREATE UNIQUE INDEX budget_lines_account_branch ON budget_lines (budget_id, account_id, COALESCE(branch_id, '00000000-0000-0000-0000-000000000000'::uuid))");

        // Rent, salaries accruals, loan instalments … posted on schedule.
        Schema::create('recurring_journals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->jsonb('lines');                         // [{account_id, debit, credit, branch_id, description}]
            $table->string('frequency', 20);                // monthly | quarterly | yearly
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active'); // active | paused | finished
            $table->unsignedInteger('runs_count')->default(0);
            $table->timestampTz('last_run_at')->nullable();
            $table->uuid('last_journal_id')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['status', 'next_run_date']);
        });

        Schema::create('year_end_closings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->date('year_start');
            $table->date('year_end');
            $table->decimal('net_income', 19, 4);
            $table->uuid('journal_entry_id')->nullable();
            $table->string('status', 20);                   // closed | reopened
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        DB::statement("CREATE UNIQUE INDEX year_end_closings_once ON year_end_closings (company_id, year_end) WHERE status = 'closed'");

        // Profit is closed into retained earnings.
        foreach (DB::table('companies')->get(['id', 'tenant_id']) as $company) {
            if (DB::table('posting_accounts')->where('company_id', $company->id)->where('key', 'retained_earnings')->exists()) {
                continue;
            }
            $accountId = DB::table('chart_of_accounts')->where('company_id', $company->id)->where('code', '3020')->value('id');
            if ($accountId !== null) {
                DB::table('posting_accounts')->insert(['tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'key' => 'retained_earnings', 'account_id' => $accountId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('year_end_closings');
        Schema::dropIfExists('recurring_journals');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
