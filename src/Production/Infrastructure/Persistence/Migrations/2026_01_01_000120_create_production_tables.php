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
        // A recipe / bill of materials: what goes into `yield_quantity` of a product.
        Schema::create('recipes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');                     // what it makes
            $table->decimal('yield_quantity', 20, 6)->default(1);
            // Restaurants / cafés: selling the item takes its ingredients out of
            // stock at the moment of sale; the item itself holds no stock.
            $table->boolean('consume_on_sale')->default(false);
            $table->string('notes', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('product_id')->references('id')->on('products');
        });
        // One active recipe per product.
        DB::statement("CREATE UNIQUE INDEX recipes_one_active ON recipes (product_id) WHERE status = 'active'");

        Schema::create('recipe_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id');
            $table->uuid('product_id');                     // ingredient / material
            $table->decimal('quantity', 20, 6);             // per yield_quantity, in base units
            $table->decimal('wastage_percent', 7, 3)->default(0);

            $table->foreign('recipe_id')->references('id')->on('recipes')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
        });

        Schema::create('production_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('warehouse_id');
            $table->uuid('recipe_id');
            $table->uuid('product_id');
            $table->string('order_no', 50);
            $table->decimal('planned_quantity', 20, 6);
            $table->decimal('produced_quantity', 20, 6)->nullable();
            $table->string('status', 20);                   // planned | completed | cancelled
            $table->decimal('material_cost', 19, 4)->default(0);
            $table->decimal('extra_cost', 19, 4)->default(0); // labour, power, overheads
            $table->decimal('unit_cost', 19, 4)->nullable();
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('recipe_id')->references('id')->on('recipes');
            $table->unique(['company_id', 'order_no']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        $this->addProductionCostAccount();

        Schema::create('production_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('production_order_id');
            $table->uuid('product_id');
            $table->decimal('required_quantity', 20, 6);    // from the recipe, wastage included
            $table->decimal('issued_quantity', 20, 6)->nullable(); // what was really used
            $table->decimal('unit_cost', 19, 4)->nullable();

            $table->foreign('production_order_id')->references('id')->on('production_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
        });
    }

    /**
     * Companies that existed before this module get the account that
     * production labour and overheads are credited to.
     */
    private function addProductionCostAccount(): void
    {
        $companies = DB::table('companies')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('posting_accounts')
            ->whereColumn('posting_accounts.company_id', 'companies.id')->where('posting_accounts.key', 'production_overhead'))
            ->get(['id', 'tenant_id']);

        foreach ($companies as $company) {
            $accountId = DB::table('chart_of_accounts')->where('company_id', $company->id)->where('code', '5250')->value('id');
            if ($accountId === null) {
                $accountId = (string) \Illuminate\Support\Str::uuid7();
                DB::table('chart_of_accounts')->insert([
                    'id' => $accountId, 'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'code' => '5250',
                    'name' => 'Production Costs Applied', 'account_type' => 'expense', 'is_system' => true, 'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('posting_accounts')->insert([
                'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'key' => 'production_overhead', 'account_id' => $accountId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_items');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
    }
};
