<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->string('area', 60)->default('Main hall');
            $table->string('name', 30);
            $table->unsignedSmallInteger('seats')->default(4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->unique(['branch_id', 'name']);
        });

        // "Spice level", "Add-ons", "Size": choices with a price difference.
        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 80);
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->default(1);
            $table->jsonb('options');                    // [{id, name, price}]
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('product_modifier_groups', function (Blueprint $table): void {
            $table->uuid('product_id');
            $table->uuid('modifier_group_id');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['product_id', 'modifier_group_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('modifier_group_id')->references('id')->on('modifier_groups')->cascadeOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('kitchen_station', 40)->nullable(); // Kitchen, Grill, Bar, Bakery … null = not sent to a kitchen
        });

        Schema::create('dine_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('table_id')->nullable();
            $table->string('order_no', 40);
            $table->string('order_type', 20);            // dine_in | takeaway | delivery
            $table->unsignedSmallInteger('guests')->default(1);
            $table->uuid('waiter_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('delivery_address', 300)->nullable();
            $table->string('status', 20);                // open | billed | paid | cancelled
            $table->decimal('service_charge_percent', 6, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->uuid('sale_id')->nullable();
            $table->uuid('opened_by');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('table_id')->references('id')->on('dining_tables');
            $table->index(['branch_id', 'status']);
        });
        // A table carries one open order at a time.
        DB::statement("CREATE UNIQUE INDEX dine_orders_one_open_per_table ON dine_orders (table_id) WHERE status IN ('open', 'billed') AND table_id IS NOT NULL");

        Schema::create('dine_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('dine_order_id');
            $table->uuid('product_id');
            $table->string('product_name', 200);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 19, 4);        // menu price + modifiers
            $table->jsonb('modifiers')->nullable();      // [{group, name, price}]
            $table->string('note', 200)->nullable();     // "no onions"
            $table->string('station', 40)->nullable();
            // pending (not yet sent) → sent → preparing → ready → served; void
            $table->string('kitchen_status', 20)->default('pending');
            $table->unsignedInteger('kot_no')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('dine_order_id')->references('id')->on('dine_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
            $table->index(['kitchen_status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dine_order_lines');
        Schema::dropIfExists('dine_orders');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('kitchen_station'));
        Schema::dropIfExists('product_modifier_groups');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('dining_tables');
    }
};
