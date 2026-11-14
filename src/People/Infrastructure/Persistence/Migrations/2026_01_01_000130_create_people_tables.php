<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Ledger accounts payroll posts to: [code, name, type, posting key]. */
    public const array ACCOUNTS = [
        ['1150', 'Employee Advances & Loans', 'asset', 'employee_advances'],
        ['2300', 'Salaries Payable', 'liability', 'salaries_payable'],
        ['2310', 'Payroll Deductions Payable', 'liability', 'payroll_deductions_payable'],
        ['5200', 'Salaries & Wages', 'expense', 'salaries_expense'],
    ];

    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('father_name', 150)->nullable();
            $table->string('cnic', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('department', 80)->nullable();
            $table->string('designation', 80)->nullable();
            $table->date('join_date');
            $table->date('leave_date')->nullable();
            $table->string('pay_type', 20)->default('monthly'); // monthly | daily
            $table->decimal('basic_salary', 19, 4);              // per month, or per day for daily wagers
            $table->jsonb('allowances')->nullable();              // [{name, amount}] fixed monthly
            $table->jsonb('deductions')->nullable();              // [{name, amount}] fixed monthly (EOBI, PF …)
            $table->decimal('monthly_tax', 19, 4)->default(0);    // withholding, as computed by the employer
            $table->decimal('annual_leave_days', 6, 2)->default(0);
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account', 60)->nullable();
            $table->uuid('user_id')->nullable();                  // when the employee also logs in
            $table->string('status', 20)->default('active');
            $table->jsonb('custom')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->unique(['tenant_id', 'code']);
        });

        // Daily register. One row per employee per day; payroll counts it.
        Schema::create('attendance', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employee_id');
            $table->date('date');
            $table->string('status', 20);                // present | absent | leave | half_day | holiday
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->string('note', 200)->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->unique(['employee_id', 'date']);
            $table->index(['tenant_id', 'date']);
        });

        Schema::create('employee_advances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->string('advance_no', 50);
            $table->date('date');
            $table->string('kind', 20);                  // advance | loan
            $table->decimal('amount', 19, 4);
            $table->decimal('installment', 19, 4);       // recovered from each payroll
            $table->decimal('recovered', 19, 4)->default(0);
            $table->string('paid_from', 20);             // cash | bank
            $table->string('status', 20);                // open | settled
            $table->string('note', 300)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->unique(['company_id', 'advance_no']);
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();       // null = all branches
            $table->string('run_no', 50);
            $table->string('period', 7);                 // 2026-11
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20);                // draft | approved | paid | cancelled
            $table->decimal('gross_total', 19, 4)->default(0);
            $table->decimal('deduction_total', 19, 4)->default(0);
            $table->decimal('net_total', 19, 4)->default(0);
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('payment_journal_entry_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['company_id', 'run_no']);
        });
        // One live run per company, period and branch scope.
        DB::statement("CREATE UNIQUE INDEX payroll_runs_one_per_period ON payroll_runs (company_id, period, COALESCE(branch_id, '00000000-0000-0000-0000-000000000000'::uuid)) WHERE status <> 'cancelled'");

        Schema::create('payroll_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payroll_run_id');
            $table->uuid('employee_id');
            $table->decimal('payable_days', 6, 2);
            $table->decimal('absent_days', 6, 2)->default(0);
            $table->decimal('overtime_hours', 7, 2)->default(0);
            $table->decimal('basic', 19, 4);
            $table->decimal('allowances', 19, 4)->default(0);
            $table->decimal('overtime_amount', 19, 4)->default(0);
            $table->decimal('bonus', 19, 4)->default(0);
            $table->decimal('absence_deduction', 19, 4)->default(0);
            $table->decimal('gross', 19, 4);
            $table->decimal('fixed_deductions', 19, 4)->default(0);
            $table->decimal('tax', 19, 4)->default(0);
            $table->decimal('other_deduction', 19, 4)->default(0);
            $table->decimal('advance_recovery', 19, 4)->default(0);
            $table->decimal('net', 19, 4);
            $table->jsonb('breakdown')->nullable();      // allowances / deductions by name, advances recovered
            $table->string('note', 300)->nullable();

            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        $this->mapAccountsForExistingCompanies();
    }

    private function mapAccountsForExistingCompanies(): void
    {
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
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('attendance');
        Schema::dropIfExists('employees');
    }
};
