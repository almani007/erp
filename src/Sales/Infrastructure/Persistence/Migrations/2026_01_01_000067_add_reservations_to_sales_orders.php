<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            // Stock held for this line in the order's warehouse (part of stock_balances.reserved).
            $table->decimal('reserved_quantity', 20, 6)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', fn (Blueprint $t) => $t->dropColumn('reserved_quantity'));
    }
};
