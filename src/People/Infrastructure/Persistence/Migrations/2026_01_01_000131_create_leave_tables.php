<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Annual, sick, casual, unpaid … each with a yearly allowance.
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 20);
            $table->string('name', 80);
            $table->boolean('paid')->default(true);
            $table->decimal('days_per_year', 6, 2)->default(0);   // 0 with paid = no limit; ignored when use_employee_days
            $table->boolean('use_employee_days')->default(false); // take the allowance from employees.annual_leave_days
            $table->decimal('carry_forward_max', 6, 2)->default(0);
            $table->boolean('allow_half_day')->default(true);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('half_day')->default(false);
            $table->decimal('days', 6, 2);
            $table->string('reason', 500)->nullable();
            $table->string('status', 20);                // pending | approved | rejected | cancelled
            $table->uuid('requested_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('leave_type_id')->references('id')->on('leave_types');
            $table->index(['employee_id', 'from_date']);
        });

        // Public holidays and weekly off days are paid for monthly staff and are not counted as leave.
        Schema::create('holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id')->nullable();       // null = every branch
            $table->date('date');
            $table->string('name', 120);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};
