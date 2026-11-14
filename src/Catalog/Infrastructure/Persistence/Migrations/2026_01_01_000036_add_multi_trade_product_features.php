<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What different trades need from a product:
 *
 *  - Garments / footwear: a style with size-colour variants. Each variant is
 *    a product of its own (own SKU, barcode, stock, price) pointing at the style.
 *  - Wholesale: a wholesale price from a minimum quantity, and selling in
 *    packs (carton, dozen) as well as single units.
 *  - Gold, silver, bartan sold by weight: price from a market rate that
 *    changes daily — weight × rate (+ wastage) + making charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->uuid('parent_id')->nullable();          // the style this variant belongs to
            $table->jsonb('variant_attributes')->nullable(); // {"size": "M", "colour": "Blue"}

            $table->decimal('wholesale_price', 19, 4)->default(0);   // 0 = no wholesale price
            $table->decimal('wholesale_min_qty', 20, 6)->default(0); // in base units

            $table->string('pricing_mode', 20)->default('fixed');     // fixed | rate
            $table->string('rate_code', 30)->nullable();              // market_rates.code
            $table->decimal('weight', 20, 6)->nullable();             // per piece, in the rate's unit; null = sold by weight
            $table->decimal('making_charge', 19, 4)->default(0);      // per piece (or per unit of weight when sold by weight)
            $table->decimal('wastage_percent', 8, 4)->default(0);
            $table->string('purity', 30)->nullable();                 // e.g. 22K, 925

            $table->foreign('parent_id')->references('id')->on('products');
            $table->index(['tenant_id', 'parent_id']);
        });

        // Packs a product can also be sold in. The base unit is always factor 1.
        Schema::create('product_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('name', 50);                     // Carton, Dozen, Thaan …
            $table->decimal('factor', 20, 6);               // base units in one pack
            $table->decimal('price', 19, 4)->nullable();           // null = factor × unit price
            $table->decimal('wholesale_price', 19, 4)->nullable();
            $table->string('barcode', 128)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['product_id', 'name']);
            $table->index(['tenant_id', 'barcode']);
        });

        Schema::create('market_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);        // GOLD-22K, SILVER, STEEL
            $table->string('name', 100);
            $table->string('per_unit', 20);    // gram, tola, kg
            $table->decimal('rate', 19, 4);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_rates');
        Schema::dropIfExists('product_units');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->dropColumn([
                'parent_id', 'variant_attributes', 'wholesale_price', 'wholesale_min_qty', 'pricing_mode',
                'rate_code', 'weight', 'making_charge', 'wastage_percent', 'purity',
            ]);
        });
    }
};
