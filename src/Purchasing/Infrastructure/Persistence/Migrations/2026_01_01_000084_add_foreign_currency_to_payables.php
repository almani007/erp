<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier bills and payments in a foreign currency. Base-currency columns
 * (grand_total, balance_due, amount) stay the books' figures; the foreign
 * columns are what the supplier invoiced or was paid, at the rate stated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable(); // usual invoicing currency; null = base
        });
        Schema::table('supplier_bills', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable();
            $table->decimal('fx_rate', 19, 8)->nullable();
            $table->decimal('foreign_total', 19, 4)->nullable();
            $table->decimal('foreign_balance_due', 19, 4)->nullable();
        });
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable();
            $table->decimal('fx_rate', 19, 8)->nullable();
            $table->decimal('foreign_amount', 19, 4)->nullable();
            $table->decimal('fx_difference', 19, 4)->default(0); // + = exchange loss, − = gain
        });
        Schema::table('supplier_payment_allocations', function (Blueprint $table): void {
            $table->decimal('foreign_amount', 19, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_allocations', fn (Blueprint $t) => $t->dropColumn('foreign_amount'));
        Schema::table('supplier_payments', fn (Blueprint $t) => $t->dropColumn(['currency', 'fx_rate', 'foreign_amount', 'fx_difference']));
        Schema::table('supplier_bills', fn (Blueprint $t) => $t->dropColumn(['currency', 'fx_rate', 'foreign_total', 'foreign_balance_due']));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('currency'));
    }
};
