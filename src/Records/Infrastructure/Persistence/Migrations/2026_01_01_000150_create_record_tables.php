<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A record type is a schema a business defines for itself — job cards,
        // appointments, table reservations, rental agreements — with its own
        // fields, numbering and status list. Each gets a list and a form.
        Schema::create('record_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('key', 40);          // machine name, fixed once created
            $table->string('name', 80);         // "Job card"
            $table->string('plural', 80);       // "Job cards"
            $table->string('number_prefix', 10);
            $table->jsonb('fields');            // [{key, label, type, options, required}]
            $table->jsonb('statuses')->nullable(); // ["Open", "In progress", "Done"]; null = no status
            $table->boolean('link_customer')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('record_type_id');
            $table->string('record_no', 50);
            $table->uuid('branch_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->string('status', 60)->nullable();
            $table->jsonb('data');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('record_type_id')->references('id')->on('record_types');
            $table->unique(['record_type_id', 'record_no']);
            $table->index(['tenant_id', 'record_type_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
        Schema::dropIfExists('record_types');
    }
};
