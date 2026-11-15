<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            // fixed = monthly_tax as entered | slabs = worked out from the tax slabs | none = not taxed
            $table->string('tax_method', 10)->default('fixed');
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('tax_method'));
    }
};
