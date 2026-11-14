<?php

declare(strict_types=1);

namespace Database\Seeders;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Catalog\Infrastructure\Persistence\Eloquent\ProductBarcode;
use Catalog\Infrastructure\Persistence\Eloquent\ProductCategory;
use Catalog\Infrastructure\Persistence\Eloquent\TaxCode;
use Catalog\Infrastructure\Persistence\Eloquent\Unit;
use Finance\Infrastructure\Persistence\Eloquent\Account;
use Finance\Infrastructure\Persistence\Eloquent\AccountingPeriod;
use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inventory\Application\PostStockAdjustment;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Organization\Infrastructure\Persistence\Eloquent\Tenant;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Sales\Infrastructure\Persistence\Eloquent\PosTerminal;
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

            foreach ([['Ali Traders', '0300-1111111', '50000'], ['Sana Khan', '0301-2222222', '10000'], ['Karachi Caterers', '0302-3333333', '150000']] as $i => [$name, $phone, $limit]) {
                Customer::query()->create([
                    'customer_code' => sprintf('CUS-%06d', $i + 1),
                    'name' => $name,
                    'phone' => $phone,
                    'credit_limit' => $limit,
                    'status' => 'active',
                ]);
            }
            DB::table('document_sequences')->insert(['tenant_id' => $tenant->id, 'type' => 'customer', 'scope' => $tenant->id, 'current_value' => 3]);

            // Opening stock goes through the real posting path, so the stock
            // ledger and the general ledger start out in agreement.
            app(PostStockAdjustment::class)->execute($mainWarehouse, 'opening_balance', 'Demo opening stock', array_map(
                static fn (Product $p): array => ['product_id' => $p->id, 'qty_delta' => '100', 'unit_cost' => (string) $p->cost_price],
                $products,
            ));
        });
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
        $chart = [
            ['1010', 'Cash in Hand', 'asset', 'cash'],
            ['1020', 'Bank Accounts', 'asset', 'bank'],
            ['1100', 'Accounts Receivable', 'asset', 'accounts_receivable'],
            ['1200', 'Inventory', 'asset', 'inventory'],
            ['1500', 'Fixed Assets', 'asset', null],
            ['2010', 'Accounts Payable', 'liability', 'accounts_payable'],
            ['2100', 'Sales Tax Payable', 'liability', 'sales_tax_payable'],
            ['2200', 'Accrued Liabilities', 'liability', null],
            ['3010', "Owner's Capital", 'equity', null],
            ['3020', 'Retained Earnings', 'equity', null],
            ['3900', 'Opening Balance Equity', 'equity', 'opening_balance_equity'],
            ['4010', 'Sales Revenue', 'revenue', 'sales_revenue'],
            ['4900', 'Other Income', 'revenue', null],
            ['5010', 'Cost of Goods Sold', 'expense', 'cost_of_goods_sold'],
            ['5100', 'Inventory Adjustments', 'expense', 'inventory_adjustment'],
            ['5110', 'Cash Over / Short', 'expense', 'cash_over_short'],
            ['5200', 'Salaries & Wages', 'expense', null],
            ['5300', 'Rent', 'expense', null],
            ['5400', 'Utilities', 'expense', null],
        ];

        foreach ($chart as [$code, $name, $type, $postingKey]) {
            $account = Account::query()->create([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'account_type' => $type,
                'is_control_account' => in_array($postingKey, ['accounts_receivable', 'accounts_payable'], true),
                'is_system' => $postingKey !== null,
                'status' => 'active',
            ]);

            if ($postingKey !== null) {
                DB::table('posting_accounts')->insert([
                    'tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'key' => $postingKey, 'account_id' => $account->id,
                ]);
            }
        }

        // Monthly periods for the current calendar year, all open.
        $year = Carbon::now()->year;
        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($year, $month, 1);
            AccountingPeriod::query()->create([
                'company_id' => $company->id,
                'name' => $start->format('M Y'),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->endOfMonth()->toDateString(),
                'status' => 'open',
            ]);
        }
    }

    /** @return list<Product> */
    private function catalog(): array
    {
        // Example rates only — confirm the applicable tax treatment with your accountant.
        $gst = TaxCode::query()->create(['code' => 'GST18', 'name' => 'Sales Tax 18%', 'rate' => '18', 'type' => 'exclusive', 'status' => 'active']);
        $exempt = TaxCode::query()->create(['code' => 'EXEMPT', 'name' => 'Exempt / Zero rated', 'rate' => '0', 'type' => 'exclusive', 'status' => 'active']);

        $units = [];
        foreach (['pc' => 'Piece', 'kg' => 'Kilogram', 'l' => 'Litre', 'pk' => 'Pack'] as $abbr => $name) {
            $units[$abbr] = Unit::query()->create(['name' => $name, 'abbreviation' => $abbr, 'is_base' => true]);
        }

        $categories = [];
        foreach (['Groceries', 'Beverages', 'Dairy', 'Household', 'Personal Care'] as $i => $name) {
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

        return $products;
    }
}
