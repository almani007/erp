<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_minutes')->nullable(); // services: how long a booking takes
        });

        // Who or what is booked: a stylist, a doctor, a chair, a court, a car bay.
        Schema::create('booking_resources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->string('name', 100);
            $table->string('kind', 20)->default('staff'); // staff | room | equipment
            $table->uuid('employee_id')->nullable();
            $table->string('color', 9)->default('#2563eb');
            // {"1": [["09:00","13:00"],["14:00","19:00"]], …} — ISO weekday 1 = Monday; missing day = closed
            $table->jsonb('working_hours');
            $table->jsonb('service_ids')->nullable();     // services this resource offers; null = all
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('resource_id');
            $table->uuid('product_id');                   // the service
            $table->string('appointment_no', 40);
            $table->uuid('customer_id')->nullable();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 30)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            // booked → confirmed → arrived → done;  or no_show / cancelled
            $table->string('status', 20);
            $table->decimal('price', 19, 4);
            $table->string('notes', 500)->nullable();
            $table->uuid('sale_id')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('resource_id')->references('id')->on('booking_resources');
            $table->foreign('product_id')->references('id')->on('products');
            $table->index(['resource_id', 'starts_at']);
            $table->index(['tenant_id', 'branch_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('booking_resources');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('duration_minutes'));
    }
};
