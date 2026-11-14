<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Supplier payments from 500,000 need the branch manager, then the finance controller."
        Schema::create('approval_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_type', 40);
            $table->decimal('min_amount', 19, 4)->default(0);
            $table->jsonb('levels');                     // ["branch_manager", "finance_controller"]
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'document_type']);
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('rule_id');
            $table->string('document_type', 40);
            $table->string('summary', 300);
            $table->decimal('amount', 19, 4);
            $table->jsonb('payload');                    // the action exactly as requested, run on final approval
            $table->uuid('branch_id')->nullable();
            $table->uuid('requested_by');
            $table->unsignedSmallInteger('current_level')->default(0);
            $table->jsonb('levels');                     // copied from the rule when requested
            $table->jsonb('history')->nullable();        // [{level, role, user_id, user, decision, note, at}]
            // pending → executed (approved and done) | rejected | cancelled | failed
            $table->string('status', 20);
            $table->string('result_type', 60)->nullable();
            $table->uuid('result_id')->nullable();
            $table->string('result_label', 120)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('notifications_inbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();         // one person …
            $table->string('role', 60)->nullable();      // … or everyone with a role
            $table->string('kind', 40);                  // approval | decision | stock | fbr | finance
            $table->string('title', 200);
            $table->string('body', 1000)->nullable();
            $table->string('link', 300)->nullable();     // app route to open
            $table->jsonb('read_by')->nullable();        // user ids who have read a role notification
            $table->timestampTz('read_at')->nullable();  // for a personal one
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_inbox');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_rules');
    }
};
