<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delivery notes (challans): what left the shop for an invoice, with whom, and proof it arrived.
        Schema::create('delivery_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('sale_id');
            $table->uuid('customer_id')->nullable();
            $table->string('note_no', 50);
            $table->string('status', 20);                    // pending | dispatched | delivered | failed | cancelled
            $table->string('ship_to_name', 160)->nullable();
            $table->string('ship_to_phone', 40)->nullable();
            $table->string('ship_to_address', 500)->nullable();
            $table->date('scheduled_for')->nullable();
            $table->uuid('driver_employee_id')->nullable(); // HR employee, when the People module is on
            $table->string('driver_name', 120)->nullable();
            $table->string('vehicle_no', 40)->nullable();
            $table->decimal('cod_amount', 19, 4)->default(0);    // to collect on delivery
            $table->decimal('collected_amount', 19, 4)->default(0);
            $table->uuid('customer_payment_id')->nullable();
            $table->string('received_by', 120)->nullable();
            $table->text('signature')->nullable();           // PNG data URL drawn on the driver's phone
            $table->string('outcome_note', 500)->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->unique(['company_id', 'note_no']);
            $table->index(['tenant_id', 'status', 'scheduled_for']);
        });

        Schema::create('delivery_note_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('delivery_note_id');
            $table->uuid('sale_item_id');
            $table->uuid('product_id');
            $table->string('description', 300);
            $table->decimal('quantity', 20, 6);

            $table->foreign('delivery_note_id')->references('id')->on('delivery_notes')->cascadeOnDelete();
            $table->foreign('sale_item_id')->references('id')->on('sale_items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
        Schema::dropIfExists('delivery_notes');
    }
};
