<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extra fields a business defines for itself (e.g. "Fabric" on products,
        // "CNIC" on customers). Values live in each record's `custom` column.
        Schema::create('custom_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('entity', 20);  // product | customer | supplier
            $table->string('key', 40);     // machine name, fixed once created
            $table->string('label', 80);
            $table->string('type', 20);    // text | number | date | boolean | select
            $table->jsonb('options')->nullable(); // choices for "select"
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'entity', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
