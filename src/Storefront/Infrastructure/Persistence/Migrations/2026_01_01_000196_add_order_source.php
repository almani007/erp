<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('source', 20)->default('staff');   // staff | online | api
            $table->string('delivery_address', 300)->nullable();
        });
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('source', 20)->default('staff');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('online')->default(true);         // shown in the online store
            $table->string('image_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['online', 'image_url']));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn(['source', 'delivery_address']));
    }
};
