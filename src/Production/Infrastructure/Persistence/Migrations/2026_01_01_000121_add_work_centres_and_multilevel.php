<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where work is done and what an hour there costs: stitching line, oven, CNC, packing.
        Schema::create('work_centres', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 100);
            $table->decimal('hourly_rate', 19, 4);       // labour + machine + overhead per hour
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        // Routing: the steps to make the recipe's yield quantity.
        Schema::create('recipe_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id');
            $table->unsignedSmallInteger('sequence');
            $table->uuid('work_centre_id');
            $table->string('name', 120);
            $table->decimal('minutes', 10, 2);           // for the recipe's yield quantity

            $table->foreign('recipe_id')->references('id')->on('recipes')->cascadeOnDelete();
            $table->foreign('work_centre_id')->references('id')->on('work_centres');
        });

        Schema::table('production_orders', function (Blueprint $table): void {
            $table->uuid('parent_id')->nullable();       // made for another order (a sub-assembly)
            $table->decimal('operations_cost', 19, 4)->default(0);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', fn (Blueprint $table) => $table->dropColumn(['parent_id', 'operations_cost']));
        Schema::dropIfExists('recipe_operations');
        Schema::dropIfExists('work_centres');
    }
};
