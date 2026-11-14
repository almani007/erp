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
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('account_type', 30);
            $table->uuid('parent_id')->nullable();
            $table->boolean('is_control_account')->default(false);
            $table->boolean('is_system')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['company_id', 'code']);
        });

        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('chart_of_accounts');
        });

        // Which account each automatic posting uses (cash, sales_revenue, inventory …).
        Schema::create('posting_accounts', function (Blueprint $table): void {
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('key', 60);
            $table->uuid('account_id');

            $table->primary(['company_id', 'key']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('account_id')->references('id')->on('chart_of_accounts');
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('name', 60);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open');
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['company_id', 'start_date']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('journal_no', 50);
            $table->string('source_type', 80);
            $table->uuid('source_id')->nullable();
            $table->date('posting_date');
            $table->string('status', 20)->default('draft');
            $table->char('currency', 3);
            $table->text('description')->nullable();
            $table->uuid('reverses_entry_id')->nullable();
            $table->uuid('reversed_by_entry_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['company_id', 'journal_no']);
            $table->index(['company_id', 'posting_date']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('journal_entry_id');
            $table->uuid('account_id');
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->uuid('branch_id')->nullable();
            $table->string('party_type', 30)->nullable();
            $table->uuid('party_id')->nullable();
            $table->string('description', 255)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('account_id')->references('id')->on('chart_of_accounts');
            $table->index(['account_id', 'journal_entry_id']);
            $table->index(['party_type', 'party_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE journal_entries
                ADD CONSTRAINT journal_entries_status_check CHECK (status IN ('draft', 'posted', 'reversed'));

            ALTER TABLE journal_lines
                ADD CONSTRAINT journal_lines_one_side_check
                CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0));

            -- A journal entry is written as a draft, receives its lines, and is then
            -- switched to "posted". These guards make that the only possible path and
            -- make posted history immutable, whatever the application code does.
            CREATE OR REPLACE FUNCTION erp_journal_entry_guard() RETURNS trigger AS $$
            DECLARE
                total_debit numeric;
                total_credit numeric;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'Journal entry % is % and cannot be deleted', OLD.journal_no, OLD.status;
                    END IF;
                    RETURN OLD;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'Journal entries must be created as drafts';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'posted' THEN
                    SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
                      INTO total_debit, total_credit
                      FROM journal_lines
                     WHERE journal_entry_id = NEW.id;

                    IF total_debit = 0 OR total_debit <> total_credit THEN
                        RAISE EXCEPTION 'Journal entry % is not balanced (debit %, credit %)',
                            NEW.journal_no, total_debit, total_credit;
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'draft' THEN
                    RETURN NEW;
                END IF;

                -- The only change allowed to a posted entry: being marked as reversed.
                IF OLD.status = 'posted' AND NEW.status = 'reversed'
                   AND (to_jsonb(NEW) - 'status' - 'reversed_by_entry_id' - 'updated_at')
                     = (to_jsonb(OLD) - 'status' - 'reversed_by_entry_id' - 'updated_at') THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'Journal entry % is % and immutable; post a reversal instead', OLD.journal_no, OLD.status;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_entries_guard
                BEFORE INSERT OR UPDATE OR DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION erp_journal_entry_guard();

            CREATE OR REPLACE FUNCTION erp_journal_line_guard() RETURNS trigger AS $$
            DECLARE
                parent_status text;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'Journal lines are immutable';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    SELECT status INTO parent_status FROM journal_entries WHERE id = NEW.journal_entry_id;
                    IF parent_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'Lines can only be added to a draft journal entry';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT status INTO parent_status FROM journal_entries WHERE id = OLD.journal_entry_id;
                IF parent_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'Lines of a posted journal entry cannot be deleted';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_lines_guard
                BEFORE INSERT OR UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION erp_journal_line_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('posting_accounts');
        Schema::dropIfExists('chart_of_accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS erp_journal_line_guard(); DROP FUNCTION IF EXISTS erp_journal_entry_guard();');
    }
};
