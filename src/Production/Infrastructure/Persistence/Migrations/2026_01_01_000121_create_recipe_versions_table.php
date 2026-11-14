<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id');
            $table->unsignedInteger('version_number');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('yield_qty', 20, 6);
            $table->uuid('yield_unit_id');
            $table->decimal('expected_wastage_pct', 8, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('recipe_id')->references('id')->on('recipes')->cascadeOnDelete();
            $table->foreign('yield_unit_id')->references('id')->on('units');
            $table->unique(['recipe_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_versions');
    }
};
