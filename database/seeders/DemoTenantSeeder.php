<?php

declare(strict_types=1);

namespace Database\Seeders;

use Assets\Application\ManageFixedAssets;
use Assets\Infrastructure\Persistence\Eloquent\AssetCategory;
use Bookings\Infrastructure\Persistence\Eloquent\BookingResource;
use Catalog\Infrastructure\Persistence\Eloquent\BranchPrice;
use Catalog\Infrastructure\Persistence\Eloquent\Coupon;
use Catalog\Infrastructure\Persistence\Eloquent\MarketRate;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Catalog\Infrastructure\Persistence\Eloquent\ProductBarcode;
use Catalog\Infrastructure\Persistence\Eloquent\ProductCategory;
use Catalog\Infrastructure\Persistence\Eloquent\ProductUnit;
use Catalog\Infrastructure\Persistence\Eloquent\PromotionItem;
use Catalog\Infrastructure\Persistence\Eloquent\PromotionRule;
use Catalog\Infrastructure\Persistence\Eloquent\TaxCode;
use Catalog\Infrastructure\Persistence\Eloquent\Unit;
use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inventory\Application\PostStockAdjustment;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Organization\Infrastructure\Persistence\Eloquent\CustomField;
use Organization\Infrastructure\Persistence\Eloquent\Tenant;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use People\Application\GiveAdvance;
use People\Infrastructure\Persistence\Eloquent\Employee;
use Production\Infrastructure\Persistence\Eloquent\Recipe;
use Restaurant\Infrastructure\Persistence\Eloquent\DiningTable;
use Restaurant\Infrastructure\Persistence\Eloquent\ModifierGroup;
use Saas\Application\ProvisionTenant;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Sales\Infrastructure\Persistence\Eloquent\PosTerminal;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;

/**
 * A small supermarket-style tenant to log in to straight after setup:
 * one company, two branches, a chart of accounts with posting accounts
 * mapped, a few products with opening stock, a POS terminal and customers.
 *
 * Runs once: if the demo tenant already exists it does nothing.
 */
final class DemoTenantSeeder extends Seeder
{
    public const string SLUG = 'brixel-demo';

    public function run(): void
    {
        if (Tenant::query()->where('slug', self::SLUG)->exists()) {
            $this->command?->info('Demo tenant already present — skipping.');

            return;
        }

        DB::transaction(function (): void {
            $tenant = Tenant::query()->create([
                'slug' => self::SLUG,
                'legal_name' => 'Brixel Demo Mart',
                'status' => 'active',
                'default_timezone' => 'Asia/Karachi',
                'default_currency' => 'PKR',
            ]);

            // Everything below is created inside this tenant.
            app(TenantContext::class)->set($tenant->id);

            $company = Company::query()->create([
                'code' => 'BDM',
                'legal_name' => 'Brixel Demo Mart (Pvt) Ltd',
                'base_currency' => 'PKR',
                'fiscal_year_start_month' => 7,
            ]);

            [$headOffice, $mainWarehouse] = $this->branch($company, 'HO', 'Head Office', 'WH-MAIN', 'Main Warehouse');
            [$gulshan] = $this->branch($company, 'GUL', 'Gulshan Branch', 'WH-GUL', 'Gulshan Store Room');

            $this->users($tenant, $headOffice);
            $this->finance($company);
            $products = $this->catalog();

            PosTerminal::query()->create([
                'branch_id' => $headOffice->id,
                'warehouse_id' => $mainWarehouse->id,
                'code' => 'POS-01',
                'name' => 'Front Counter 1',
                'status' => 'active',
            ]);

            foreach ([['Ali Traders (wholesale)', '0300-1111111', '50000', 'wholesale'], ['Sana Khan', '0301-2222222', '10000', 'retail'], ['Karachi Caterers', '0302-3333333', '150000', 'wholesale']] as $i => [$name, $phone, $limit, $tier]) {
                Customer::query()->create([
                    'customer_code' => sprintf('CUS-%06d', $i + 1),
                    'name' => $name,
                    'phone' => $phone,
                    'credit_limit' => $limit,
                    'price_tier' => $tier,
                    'status' => 'active',
                ]);
            }
            DB::table('document_sequences')->insert(['tenant_id' => $tenant->id, 'type' => 'customer', 'scope' => $tenant->id, 'current_value' => 3]);

            foreach ([['Indus Foods Distribution', 'Bilal Ahmed', '021-3456789', 30], ['Karachi Beverage Co.', 'Hina Sheikh', '021-3567890', 15], ['CleanHome Supplies', 'Usman Tariq', '021-3678901', 7]] as $i => [$name, $contact, $phone, $terms]) {
                Supplier::query()->create([
                    'supplier_code' => sprintf('SUP-%06d', $i + 1),
                    'name' => $name,
                    'contact_person' => $contact,
                    'phone' => $phone,
                    'payment_terms_days' => $terms,
                    'status' => 'active',
                ]);
            }
            DB::table('document_sequences')->insert(['tenant_id' => $tenant->id, 'type' => 'supplier', 'scope' => $tenant->id, 'current_value' => 3]);

            $this->pricing($company, $gulshan, $products);

            $this->people($company, $headOffice, $gulshan);
            $this->assets($company, $headOffice, $gulshan);

            // Opening stock goes through the real posting path, so the stock
            // ledger and the general ledger start out in agreement.
            app(PostStockAdjustment::class)->execute($mainWarehouse, 'opening_balance', 'Demo opening stock', array_map(
                static fn (Product $p): array => [
                    'product_id' => $p->id,
                    'qty_delta' => $p->pricing_mode === 'rate' && $p->weight !== null ? '3' : '100', // a few pieces of jewellery, plenty of everything else
                    'unit_cost' => (string) $p->cost_price,
                    // Expiry-tracked stock needs a batch from day one.
                    'batch_no' => $p->track_expiry ? 'B-0001' : null,
                    'expiry_date' => $p->track_expiry ? now()->addMonths(18)->toDateString() : null,
                ],
                $products,
            ));

            // Loyalty: 1 point per 100 spent, each point worth 1.
            app(Settings::class)->set('loyalty', 'earn_per_amount', '100');
            app(Settings::class)->set('loyalty', 'point_value', '1');
            app(Settings::class)->set('business', 'type', 'general'); // shows every trade's fields
            app(Settings::class)->set('sales', 'quotation_terms', "Prices valid until the date shown. Delivery within 3 working days of order confirmation.");
            app(Settings::class)->set('sales', 'invoice_terms', "Goods once sold are returnable within 7 days in original condition. Payment due by the due date shown; cheques in the company's name.");

            // A tenant-defined field, to show how records can be extended without code.
            CustomField::query()->create(['entity' => 'product', 'key' => 'fabric', 'label' => 'Fabric', 'type' => 'select', 'options' => ['Cotton', 'Lawn', 'Linen', 'Silk'], 'status' => 'active']);
            CustomField::query()->create(['entity' => 'customer', 'key' => 'cnic', 'label' => 'CNIC', 'type' => 'text', 'status' => 'active']);
        });
    }

    /**
     * A running deal, a coupon and one branch-wise price, so pricing can be seen working.
     *
     * @param  list<Product>  $products
     */
    private function pricing(Company $company, Branch $gulshan, array $products): void
    {
        $beverages = PromotionRule::query()->create([
            'company_id' => $company->id, 'name' => 'Beverage Fest — 10% off', 'type' => 'percentage', 'value' => '10',
            'start_at' => now()->subDay(), 'end_at' => now()->addMonths(3), 'status' => 'active', 'requires_coupon' => false,
        ]);
        PromotionItem::query()->create([
            'promotion_rule_id' => $beverages->id,
            'category_id' => ProductCategory::query()->where('slug', 'beverages')->value('id'),
        ]);

        $welcome = PromotionRule::query()->create([
            'company_id' => $company->id, 'name' => 'Welcome coupon — 5% off everything', 'type' => 'percentage', 'value' => '5',
            'start_at' => now()->subDay(), 'status' => 'active', 'requires_coupon' => true,
        ]);
        Coupon::query()->create([
            'company_id' => $company->id, 'code' => 'WELCOME5', 'promotion_rule_id' => $welcome->id,
            'max_uses' => 100, 'valid_from' => now()->subDay(), 'status' => 'active',
        ]);

        // Sugar sells for a little more at the Gulshan branch.
        $sugar = collect($products)->firstWhere('sku', 'GRO-004');
        BranchPrice::query()->create(['branch_id' => $gulshan->id, 'product_id' => $sugar->id, 'price' => '170']);
    }

    /** @return array{0: Branch, 1: Warehouse} */
    private function branch(Company $company, string $code, string $name, string $warehouseCode, string $warehouseName): array
    {
        $branch = Branch::query()->create([
            'company_id' => $company->id, 'code' => $code, 'name' => $name, 'timezone' => 'Asia/Karachi', 'status' => 'active',
        ]);

        $warehouse = Warehouse::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => $warehouseCode, 'name' => $warehouseName, 'status' => 'active',
        ]);

        return [$branch, $warehouse];
    }

    private function users(Tenant $tenant, Branch $headOffice): void
    {
        $admin = new User(['name' => 'System Administrator', 'email' => 'admin@brixelltd.com', 'password' => 'BrixelAdmin2026!', 'status' => 'active', 'all_branches' => true]);
        $admin->tenant_id = $tenant->id;
        $admin->save();
        $admin->assignRole('tenant_owner');

        $cashier = new User(['name' => 'Demo Cashier', 'email' => 'cashier@brixelltd.com', 'password' => 'BrixelCashier2026!', 'status' => 'active', 'all_branches' => false]);
        $cashier->tenant_id = $tenant->id;
        $cashier->save();
        $cashier->assignRole('cashier');
        $cashier->branches()->attach($headOffice->id);
    }

    private function finance(Company $company): void
    {
        // Same starter chart, posting accounts and periods every provisioned tenant gets.
        app(ProvisionTenant::class)->chartOfAccounts($company);
    }

    /** Asset categories and a few assets brought in with depreciation already charged. */
    private function assets(Company $company, Branch $headOffice, Branch $gulshan): void
    {
        $categories = [];
        foreach ([
            ['Furniture & fixtures', 'straight_line', 120, null, '0'],
            ['Computers & POS hardware', 'straight_line', 36, null, '5'],
            ['Vehicles', 'reducing_balance', null, '15', '10'],
            ['Plant & machinery', 'straight_line', 120, null, '5'],
        ] as [$name, $method, $life, $rate, $residual]) {
            $categories[$name] = AssetCategory::query()->create([
                'name' => $name, 'method' => $method, 'useful_life_months' => $life, 'rate_percent' => $rate, 'residual_percent' => $residual, 'status' => 'active',
            ]);
        }

        $adminId = (string) User::query()->where('email', 'admin@brixelltd.com')->value('id');
        $lastMonthEnd = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        foreach ([
            ['Computers & POS hardware', 'POS terminal with printer and scanner', $headOffice, '185000', 12, '58583.33'],
            ['Furniture & fixtures', 'Display racks and counters', $headOffice, '650000', 18, '97500'],
            ['Vehicles', 'Suzuki Ravi delivery pickup', $gulshan, '2350000', 24, '625000'],
        ] as [$category, $name, $branch, $cost, $monthsAgo, $opening]) {
            app(ManageFixedAssets::class)->register($company->id, [
                'category_id' => $categories[$category]->id, 'name' => $name, 'branch_id' => $branch->id,
                'purchase_date' => now()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->toDateString(),
                'cost' => $cost, 'opening_depreciation' => $opening, 'depreciated_to' => $lastMonthEnd, 'funding' => 'opening',
            ], $adminId);
        }
    }

    /** Four staff on monthly and daily pay, with one salary advance being recovered. */
    private function people(Company $company, Branch $headOffice, Branch $gulshan): void
    {
        $staff = [
            ['EMP-001', 'Imran Qureshi', 'Store Manager', 'Management', $headOffice, 'monthly', '85000', [['name' => 'House rent', 'amount' => '20000'], ['name' => 'Conveyance', 'amount' => '5000']], '2500'],
            ['EMP-002', 'Ayesha Siddiqui', 'Cashier', 'Sales', $headOffice, 'monthly', '42000', [['name' => 'Conveyance', 'amount' => '3000']], '0'],
            ['EMP-003', 'Bilal Ahmed', 'Cashier', 'Sales', $gulshan, 'monthly', '40000', [['name' => 'Conveyance', 'amount' => '3000']], '0'],
            ['EMP-004', 'Rashid Khan', 'Loader', 'Warehouse', $headOffice, 'daily', '1600', [], '0'],
        ];

        $employees = [];
        foreach ($staff as [$code, $name, $designation, $department, $branch, $payType, $basic, $allowances, $tax]) {
            $employees[$code] = Employee::query()->create([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => $code, 'name' => $name,
                'designation' => $designation, 'department' => $department, 'join_date' => now()->subYear()->toDateString(),
                'pay_type' => $payType, 'basic_salary' => $basic, 'allowances' => $allowances,
                'deductions' => $payType === 'monthly' ? [['name' => 'EOBI', 'amount' => '370']] : [],
                'monthly_tax' => $tax, 'annual_leave_days' => '14', 'status' => 'active',
            ]);
        }

        app(GiveAdvance::class)->execute($employees['EMP-002'], [
            'date' => now()->toDateString(), 'kind' => 'advance', 'amount' => '15000', 'installment' => '5000', 'paid_from' => 'cash',
            'note' => 'Demo: recovered over three months',
        ]);
    }

    /** @return list<Product> */
    private function catalog(): array
    {
        // Example rates only — confirm the applicable tax treatment with your accountant.
        $gst = TaxCode::query()->create(['code' => 'GST18', 'name' => 'Sales Tax 18%', 'rate' => '18', 'type' => 'exclusive', 'status' => 'active']);
        $exempt = TaxCode::query()->create(['code' => 'EXEMPT', 'name' => 'Exempt / Zero rated', 'rate' => '0', 'type' => 'exclusive', 'status' => 'active']);

        $units = [];
        foreach (['pc' => 'Piece', 'kg' => 'Kilogram', 'l' => 'Litre', 'pk' => 'Pack', 'g' => 'Gram'] as $abbr => $name) {
            $units[$abbr] = Unit::query()->create(['name' => $name, 'abbreviation' => $abbr, 'is_base' => true]);
        }

        $categories = [];
        foreach (['Groceries', 'Beverages', 'Dairy', 'Household', 'Personal Care', 'Garments', 'Jewellery', 'Bartan'] as $i => $name) {
            $categories[$name] = ProductCategory::query()->create(['name' => $name, 'slug' => Str::slug($name), 'sort_order' => $i, 'status' => 'active']);
        }

        // sku, name, category, unit, cost, price, tax, barcode
        $rows = [
            ['GRO-001', 'Basmati Rice 5kg', 'Groceries', 'pk', '1450', '1750', $exempt, '8964000100011'],
            ['GRO-002', 'Wheat Flour 10kg', 'Groceries', 'pk', '1250', '1480', $exempt, '8964000100028'],
            ['GRO-003', 'Cooking Oil 1L', 'Groceries', 'pc', '520', '610', $gst, '8964000100035'],
            ['GRO-004', 'Sugar 1kg', 'Groceries', 'kg', '140', '165', $gst, '8964000100042'],
            ['BEV-001', 'Mineral Water 1.5L', 'Beverages', 'pc', '55', '80', $gst, '8964000200018'],
            ['BEV-002', 'Cola 500ml', 'Beverages', 'pc', '70', '100', $gst, '8964000200025'],
            ['BEV-003', 'Black Tea 475g', 'Beverages', 'pk', '780', '920', $gst, '8964000200032'],
            ['DAI-001', 'UHT Milk 1L', 'Dairy', 'pc', '250', '290', $exempt, '8964000300015'],
            ['DAI-002', 'Yogurt 500g', 'Dairy', 'pc', '110', '140', $exempt, '8964000300022'],
            ['HOU-001', 'Dishwash Liquid 500ml', 'Household', 'pc', '210', '275', $gst, '8964000400012'],
            ['HOU-002', 'Laundry Detergent 1kg', 'Household', 'pk', '390', '480', $gst, '8964000400029'],
            ['PER-001', 'Bath Soap 130g', 'Personal Care', 'pc', '85', '120', $gst, '8964000500019'],
        ];

        $products = [];
        foreach ($rows as [$sku, $name, $category, $unit, $cost, $price, $tax, $barcode]) {
            $product = Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'product_type' => 'goods',
                'base_unit_id' => $units[$unit]->id,
                'category_id' => $categories[$category]->id,
                'tax_code_id' => $tax->id,
                'cost_price' => $cost,
                'sale_price' => $price,
                'reorder_level' => '20',
                'status' => 'active',
            ]);

            ProductBarcode::query()->create([
                'product_id' => $product->id, 'barcode' => $barcode, 'unit_id' => $units[$unit]->id, 'is_primary' => true,
            ]);

            $products[] = $product;
        }

        // ── One example per trade ────────────────────────────────

        // Wholesale: sugar has a wholesale price from 10 kg, and cola also sells by the carton.
        collect($products)->firstWhere('sku', 'GRO-004')->update(['wholesale_price' => '152', 'wholesale_min_qty' => '10']);
        $cola = collect($products)->firstWhere('sku', 'BEV-002');
        ProductUnit::query()->create(['product_id' => $cola->id, 'name' => 'Carton (24)', 'factor' => '24', 'price' => '2280', 'wholesale_price' => '2160', 'barcode' => '18964000200022']);

        // Garments: a style with size/colour variants, each its own SKU and stock.
        $style = Product::query()->create([
            'sku' => 'GAR-KURTA', 'name' => 'Cotton Kurta', 'product_type' => 'goods', 'base_unit_id' => $units['pc']->id,
            'category_id' => $categories['Garments']->id, 'tax_code_id' => $gst->id, 'cost_price' => '1400', 'sale_price' => '2200',
            'wholesale_price' => '1850', 'wholesale_min_qty' => '6', 'reorder_level' => '5', 'status' => 'inactive',
        ]);
        foreach (['S', 'M', 'L'] as $size) {
            foreach (['White', 'Navy'] as $colour) {
                $products[] = Product::query()->create([
                    'sku' => strtoupper("GAR-KURTA-{$size}-{$colour}"), 'name' => "Cotton Kurta — {$size} / {$colour}",
                    'product_type' => 'goods', 'base_unit_id' => $units['pc']->id, 'category_id' => $categories['Garments']->id,
                    'tax_code_id' => $gst->id, 'cost_price' => '1400', 'sale_price' => '2200', 'wholesale_price' => '1850',
                    'wholesale_min_qty' => '6', 'reorder_level' => '5', 'status' => 'active',
                    'parent_id' => $style->id, 'variant_attributes' => ['Size' => $size, 'Colour' => $colour],
                ]);
            }
        }

        // Pharmacy / food: batch and expiry tracked, sold first-expiry-first-out.
        $products[] = Product::query()->create([
            'sku' => 'PHA-PARA-500', 'name' => 'Paracetamol 500mg (strip of 10)', 'product_type' => 'goods', 'base_unit_id' => $units['pk']->id,
            'category_id' => $categories['Personal Care']->id, 'tax_code_id' => $exempt->id, 'cost_price' => '28', 'sale_price' => '40',
            'reorder_level' => '50', 'status' => 'active', 'track_batch' => true, 'track_expiry' => true,
        ]);

        // Electronics / mobile: one serial or IMEI per unit, with a warranty period.
        $products[] = Product::query()->create([
            'sku' => 'ELE-PHONE-A1', 'name' => 'Smartphone A1 64GB', 'product_type' => 'goods', 'base_unit_id' => $units['pc']->id,
            'category_id' => $categories['Household']->id, 'tax_code_id' => $gst->id, 'cost_price' => '42000', 'sale_price' => '48500',
            'reorder_level' => '2', 'status' => 'active', 'track_serial' => true, 'warranty_months' => 12,
        ]);

        // Rates are examples — enter the real rate of the day before selling.
        MarketRate::query()->create(['code' => 'GOLD-22K', 'name' => 'Gold 22 karat', 'per_unit' => 'gram', 'rate' => '38000']);
        MarketRate::query()->create(['code' => 'STEEL', 'name' => 'Stainless steel bartan', 'per_unit' => 'kg', 'rate' => '1450']);

        // Jewellery: a piece priced from the gold rate — 8.5 g, 6% wastage, making charge on top.
        $products[] = Product::query()->create([
            'sku' => 'JWL-RING-001', 'name' => 'Gold Ring 22K (8.5 g)', 'product_type' => 'goods', 'base_unit_id' => $units['pc']->id,
            'category_id' => $categories['Jewellery']->id, 'tax_code_id' => $exempt->id, 'cost_price' => '330000', 'sale_price' => '0',
            'pricing_mode' => 'rate', 'rate_code' => 'GOLD-22K', 'weight' => '8.5', 'wastage_percent' => '6', 'making_charge' => '12000',
            'purity' => '22K', 'reorder_level' => '0', 'status' => 'active',
        ]);

        // Bartan sold by weight: the quantity on the sale is the weight in kg.
        $products[] = Product::query()->create([
            'sku' => 'BRT-STEEL-KG', 'name' => 'Steel Bartan (by weight)', 'product_type' => 'goods', 'base_unit_id' => $units['kg']->id,
            'category_id' => $categories['Bartan']->id, 'tax_code_id' => $gst->id, 'cost_price' => '1150', 'sale_price' => '0',
            'pricing_mode' => 'rate', 'rate_code' => 'STEEL', 'reorder_level' => '25', 'status' => 'active',
        ]);
        // …and bartan sold by the piece, with a set (dozen) price for wholesale.
        $glass = Product::query()->create([
            'sku' => 'BRT-GLASS', 'name' => 'Steel Glass', 'product_type' => 'goods', 'base_unit_id' => $units['pc']->id,
            'category_id' => $categories['Bartan']->id, 'tax_code_id' => $gst->id, 'cost_price' => '160', 'sale_price' => '240',
            'wholesale_price' => '200', 'wholesale_min_qty' => '12', 'reorder_level' => '24', 'status' => 'active',
        ]);
        ProductUnit::query()->create(['product_id' => $glass->id, 'name' => 'Dozen', 'factor' => '12', 'price' => '2640', 'wholesale_price' => '2340']);
        $products[] = $glass;

        // Restaurant / café: a cup of chai holds no stock; selling it takes milk and tea leaves out.
        $find = static fn (string $sku): Product => collect($products)->firstWhere('sku', $sku);
        $chai = Product::query()->create([
            'sku' => 'CAF-CHAI', 'name' => 'Doodh Patti Chai (cup)', 'product_type' => 'non_stock', 'base_unit_id' => $units['pc']->id,
            'category_id' => $categories['Beverages']->id, 'tax_code_id' => $exempt->id, 'cost_price' => '0', 'sale_price' => '120', 'status' => 'active',
        ]);
        $chaiRecipe = Recipe::query()->create(['product_id' => $chai->id, 'yield_quantity' => '1', 'consume_on_sale' => true, 'status' => 'active']);
        $chaiRecipe->items()->create(['product_id' => $find('DAI-001')->id, 'quantity' => '0.15', 'wastage_percent' => '0']);  // litres
        $chaiRecipe->items()->create(['product_id' => $find('BEV-003')->id, 'quantity' => '0.01', 'wastage_percent' => '0']);  // packs of 475 g
        $chaiRecipe->items()->create(['product_id' => $find('GRO-004')->id, 'quantity' => '0.015', 'wastage_percent' => '0']); // kg

        // Small production: sweet lassi is made in batches of 10 bottles from yogurt and sugar.
        $lassi = Product::query()->create([
            'sku' => 'MFG-LASSI', 'name' => 'Sweet Lassi 500ml (made here)', 'product_type' => 'goods', 'base_unit_id' => $units['pc']->id,
            'category_id' => $categories['Dairy']->id, 'tax_code_id' => $exempt->id, 'cost_price' => '0', 'sale_price' => '180', 'status' => 'active',
        ]);
        $lassiRecipe = Recipe::query()->create(['product_id' => $lassi->id, 'yield_quantity' => '10', 'consume_on_sale' => false, 'status' => 'active']);
        $lassiRecipe->items()->create(['product_id' => $find('DAI-002')->id, 'quantity' => '10', 'wastage_percent' => '2']);
        $lassiRecipe->items()->create(['product_id' => $find('GRO-004')->id, 'quantity' => '0.5', 'wastage_percent' => '0']);

        // Restaurant: tables, a sugar choice on chai, and a kitchen station for made dishes.
        $chai->update(['kitchen_station' => 'Tea counter']);
        foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6'] as $i => $name) {
            DiningTable::query()->create(['branch_id' => Branch::query()->where('code', 'HO')->value('id'), 'area' => $i < 4 ? 'Main hall' : 'Family hall', 'name' => $name, 'seats' => $i < 4 ? 4 : 6, 'sort_order' => $i]);
        }
        $sugar = ModifierGroup::query()->create(['name' => 'Sugar', 'min_select' => 1, 'max_select' => 1, 'status' => 'active', 'options' => [
            ['id' => 'sugar-normal', 'name' => 'Normal sugar', 'price' => '0'], ['id' => 'sugar-less', 'name' => 'Less sugar', 'price' => '0'],
            ['id' => 'sugar-none', 'name' => 'No sugar', 'price' => '0'], ['id' => 'sugar-gur', 'name' => 'Gur instead', 'price' => '20'],
        ]]);
        DB::table('product_modifier_groups')->insert(['product_id' => $chai->id, 'modifier_group_id' => $sugar->id, 'sort_order' => 0]);

        // Services and rentals: a timed service and a rent item (no stock of their own).
        $haircut = Product::query()->create([
            'sku' => 'SRV-HAIRCUT', 'name' => 'Haircut & styling', 'product_type' => 'service', 'base_unit_id' => $units['pc']->id,
            'category_id' => null, 'tax_code_id' => $exempt->id, 'cost_price' => '0', 'sale_price' => '800', 'status' => 'active', 'duration_minutes' => 45,
        ]);
        BookingResource::query()->create([
            'branch_id' => Branch::query()->where('code', 'HO')->value('id'), 'name' => 'Stylist — Nadia', 'kind' => 'staff', 'color' => '#db2777',
            'working_hours' => array_fill_keys(['1', '2', '3', '4', '5', '6'], [['11:00', '15:00'], ['16:00', '21:00']]), 'service_ids' => [$haircut->id], 'status' => 'active',
        ]);
        Product::query()->create([
            'sku' => 'RNT-GENSET', 'name' => 'Generator rent', 'product_type' => 'service', 'base_unit_id' => $units['pc']->id,
            'category_id' => null, 'tax_code_id' => $exempt->id, 'cost_price' => '0', 'sale_price' => '15000', 'status' => 'active',
        ]);

        return $products;
    }
}
