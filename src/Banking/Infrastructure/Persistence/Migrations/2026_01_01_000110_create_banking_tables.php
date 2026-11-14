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
        // Each bank account (and cash box) has its own ledger account.
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('account_id');                 // chart_of_accounts
            $table->string('kind', 10);                 // bank | cash
            $table->string('name', 120);
            $table->string('bank_name', 120)->nullable();
            $table->string('branch_name', 120)->nullable();
            $table->string('account_no', 60)->nullable();
            $table->string('iban', 40)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('account_id')->references('id')->on('chart_of_accounts');
            $table->unique(['company_id', 'account_id']);
        });

        // Post-dated and current cheques, received and issued.
        Schema::create('cheques', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('direction', 10);             // received | issued
            $table->uuid('bank_account_id')->nullable(); // deposited into / drawn on
            $table->string('party_type', 20);            // customer | supplier
            $table->uuid('party_id');
            $table->string('cheque_no', 40);
            $table->string('drawer_bank', 120)->nullable();
            $table->date('cheque_date');                 // can be cashed from
            $table->decimal('amount', 19, 4);
            // received: in_hand → deposited → cleared | bounced;  issued: issued → cleared | bounced;  either: cancelled
            $table->string('status', 20);
            $table->date('deposited_on')->nullable();
            $table->date('cleared_on')->nullable();
            $table->uuid('payment_id')->nullable();      // customer or supplier payment created on clearing
            $table->string('bounce_reason', 300)->nullable();
            $table->string('notes', 300)->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts');
            $table->index(['tenant_id', 'status', 'cheque_date']);
            $table->index(['party_type', 'party_id']);
        });

        Schema::create('bank_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('transfer_no', 50);
            $table->uuid('from_account_id');
            $table->uuid('to_account_id');
            $table->date('transfer_date');
            $table->decimal('amount', 19, 4);
            $table->string('reference', 120)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('from_account_id')->references('id')->on('bank_accounts');
            $table->foreign('to_account_id')->references('id')->on('bank_accounts');
            $table->unique(['company_id', 'transfer_no']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('bank_account_id');
            $table->date('statement_date');
            $table->decimal('statement_balance', 19, 4);
            $table->string('status', 20);                // draft | completed
            $table->uuid('created_by');
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts');
        });
        DB::statement("CREATE UNIQUE INDEX bank_reconciliations_one_draft ON bank_reconciliations (bank_account_id) WHERE status = 'draft'");

        // Ledger lines matched to the bank statement. Journal lines are
        // immutable, so the tick lives here; each line is matched once.
        Schema::create('bank_reconciliation_lines', function (Blueprint $table): void {
            $table->uuid('bank_reconciliation_id');
            $table->unsignedBigInteger('journal_line_id');

            $table->foreign('bank_reconciliation_id')->references('id')->on('bank_reconciliations')->cascadeOnDelete();
            $table->foreign('journal_line_id')->references('id')->on('journal_lines');
            $table->primary(['bank_reconciliation_id', 'journal_line_id']);
            $table->unique('journal_line_id');
        });

        // Existing companies: their default cash and bank ledger accounts become the first two.
        foreach (DB::table('companies')->get(['id', 'tenant_id']) as $company) {
            foreach (['cash' => 'Cash in hand', 'bank' => 'Main bank account'] as $key => $name) {
                $accountId = DB::table('posting_accounts')->where('company_id', $company->id)->where('key', $key)->value('account_id');
                if ($accountId === null || DB::table('bank_accounts')->where('account_id', $accountId)->exists()) {
                    continue;
                }
                DB::table('bank_accounts')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'account_id' => $accountId,
                    'kind' => $key, 'name' => $name, 'is_default' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_transfers');
        Schema::dropIfExists('cheques');
        Schema::dropIfExists('bank_accounts');
    }
};
