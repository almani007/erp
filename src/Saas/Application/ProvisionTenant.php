<?php

declare(strict_types=1);

namespace Saas\Application;

use Catalog\Infrastructure\Persistence\Eloquent\TaxCode;
use Catalog\Infrastructure\Persistence\Eloquent\Unit;
use Finance\Infrastructure\Persistence\Eloquent\Account;
use Finance\Infrastructure\Persistence\Eloquent\AccountingPeriod;
use Identity\Infrastructure\Persistence\Eloquent\User;
use Identity\Infrastructure\Provider\IdentityServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Organization\Infrastructure\Persistence\Eloquent\Tenant;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Saas\Infrastructure\Persistence\Eloquent\BusinessTemplate;
use Sales\Infrastructure\Persistence\Eloquent\PosTerminal;
use Shared\Domain\Contract\TenantContext;

/**
 * Creates a ready-to-use tenant: company, first branch and warehouse, the
 * owner's login, a starter chart of accounts with posting accounts mapped,
 * this year's accounting periods, basic units, a tax-exempt code and a POS
 * terminal — then shapes it with a business template.
 */
final class ProvisionTenant
{
    /** code, name, type, posting key (what automatic postings use it for) */
    public const array CHART = [
        ['1010', 'Cash in Hand', 'asset', 'cash'],
        ['1020', 'Bank Accounts', 'asset', 'bank'],
        ['1100', 'Accounts Receivable', 'asset', 'accounts_receivable'],
        ['1150', 'Employee Advances & Loans', 'asset', 'employee_advances'],
        ['1200', 'Inventory', 'asset', 'inventory'],
        ['1300', 'Input Tax Receivable', 'asset', 'purchase_tax_receivable'],
        ['1500', 'Fixed Assets', 'asset', null],
        ['2010', 'Accounts Payable', 'liability', 'accounts_payable'],
        ['2100', 'Sales Tax Payable', 'liability', 'sales_tax_payable'],
        ['2200', 'Accrued Liabilities', 'liability', null],
        ['2300', 'Salaries Payable', 'liability', 'salaries_payable'],
        ['2310', 'Payroll Deductions Payable', 'liability', 'payroll_deductions_payable'],
        ['3010', "Owner's Capital", 'equity', null],
        ['3020', 'Retained Earnings', 'equity', null],
        ['3900', 'Opening Balance Equity', 'equity', 'opening_balance_equity'],
        ['4010', 'Sales Revenue', 'revenue', 'sales_revenue'],
        ['4900', 'Other Income', 'revenue', null],
        ['5010', 'Cost of Goods Sold', 'expense', 'cost_of_goods_sold'],
        ['5100', 'Inventory Adjustments', 'expense', 'inventory_adjustment'],
        ['5110', 'Cash Over / Short', 'expense', 'cash_over_short'],
        ['5120', 'Loyalty Redemptions', 'expense', 'loyalty_redemption'],
        ['5130', 'Purchase Price Variance', 'expense', 'purchase_price_variance'],
        ['5200', 'Salaries & Wages', 'expense', 'salaries_expense'],
        ['5250', 'Production Costs Applied', 'expense', 'production_overhead'],
        ['5300', 'Rent', 'expense', null],
        ['5400', 'Utilities', 'expense', null],
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ApplyBusinessTemplate $applyTemplate,
    ) {}

    /**
     * @param  array{slug: string, legal_name: string, currency: string, timezone: string, owner_name: string, owner_email: string, owner_password: string, entitlements?: array<string, mixed>|null}  $input
     */
    public function execute(array $input, ?BusinessTemplate $template): Tenant
    {
        $previous = $this->tenant->id();

        try {
            return $this->provision($input, $template);
        } finally {
            if ($previous !== null) {
                $this->tenant->set($previous);
            }
        }
    }

    /** @param  array<string, mixed>  $input */
    private function provision(array $input, ?BusinessTemplate $template): Tenant
    {
        return DB::transaction(function () use ($input, $template): Tenant {
            $tenant = Tenant::query()->create([
                'slug' => $input['slug'],
                'legal_name' => $input['legal_name'],
                'status' => 'active',
                'default_timezone' => $input['timezone'],
                'default_currency' => $input['currency'],
            ]);

            if (! empty($input['entitlements'])) {
                DB::table('tenants')->where('id', $tenant->id)->update(['entitlements' => json_encode($input['entitlements'], JSON_THROW_ON_ERROR)]);
            }

            // From here on, everything is created inside the new tenant.
            $this->tenant->set($tenant->id);

            $company = Company::query()->create([
                'code' => 'MAIN', 'legal_name' => $input['legal_name'], 'base_currency' => $input['currency'], 'fiscal_year_start_month' => 7,
            ]);
            $branch = Branch::query()->create([
                'company_id' => $company->id, 'code' => 'HO', 'name' => 'Head Office', 'timezone' => $input['timezone'], 'status' => 'active',
            ]);
            $warehouse = Warehouse::query()->create([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'WH-MAIN', 'name' => 'Main Warehouse', 'status' => 'active',
            ]);
            PosTerminal::query()->create([
                'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'code' => 'POS-01', 'name' => 'Counter 1', 'status' => 'active',
            ]);

            $owner = new User([
                'name' => $input['owner_name'], 'email' => mb_strtolower($input['owner_email']), 'password' => $input['owner_password'],
                'status' => 'active', 'all_branches' => true,
            ]);
            $owner->tenant_id = $tenant->id;
            $owner->save();
            $owner->assignRole(IdentityServiceProvider::OWNER_ROLE);

            $this->chartOfAccounts($company);

            foreach (['pc' => 'Piece', 'kg' => 'Kilogram', 'g' => 'Gram', 'l' => 'Litre', 'pk' => 'Pack', 'hr' => 'Hour'] as $abbreviation => $name) {
                Unit::query()->create(['name' => $name, 'abbreviation' => $abbreviation, 'is_base' => true]);
            }
            // Tax rates differ by country and product; the tenant adds its own codes.
            TaxCode::query()->create(['code' => 'EXEMPT', 'name' => 'Exempt / no tax', 'rate' => '0', 'type' => 'exclusive', 'status' => 'active']);

            if ($template !== null) {
                $this->applyTemplate->execute($tenant->id, $template);
            }

            return $tenant->refresh();
        });
    }

    public function chartOfAccounts(Company $company): void
    {
        foreach (self::CHART as [$code, $name, $type, $postingKey]) {
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
}
