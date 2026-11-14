<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->uuid('from_unit_id');
            $table->uuid('to_unit_id');
            $table->decimal('factor', 20, 6);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('from_unit_id')->references('id')->on('units');
            $table->foreign('to_unit_id')->references('id')->on('units');
            $table->unique(['tenant_id', 'from_unit_id', 'to_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_conversions');
    }
};
