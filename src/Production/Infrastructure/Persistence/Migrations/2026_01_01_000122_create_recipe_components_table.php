<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('recipe_version_id');
            $table->uuid('product_id')->comment('Raw material');
            $table->decimal('qty', 20, 6);
            $table->uuid('unit_id');
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->foreign('recipe_version_id')->references('id')->on('recipe_versions')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('unit_id')->references('id')->on('units');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_components');
    }
};
