<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission names are `module.resource.action` and match the route
 * middleware in src/<Module>/Presentation/Routes/api.php exactly.
 * Safe to re-run: it only adds what is missing and re-syncs role grants.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    private const string GUARD = 'web';

    private const array PERMISSIONS = [
        'organization.company.view', 'organization.company.manage',
        'organization.branch.manage', 'organization.warehouse.manage',
        'organization.settings.view', 'organization.settings.manage',

        'identity.user.view', 'identity.user.manage', 'identity.audit.view',

        'catalog.product.view', 'catalog.product.manage', 'catalog.master.manage', 'catalog.pricing.manage',

        'inventory.stock.view', 'inventory.adjustment.create', 'inventory.transfer.manage', 'inventory.count.manage',

        'sales.sale.view', 'sales.sale.create', 'sales.sale.void', 'sales.price.override',
        'sales.shift.manage', 'sales.customer.view', 'sales.customer.manage',
        'sales.return.create', 'sales.payment.receive',

        'purchasing.supplier.view', 'purchasing.supplier.manage', 'purchasing.order.view', 'purchasing.order.create',
        'purchasing.order.approve', 'purchasing.receipt.create', 'purchasing.payment.create',

        'finance.account.view', 'finance.account.manage',
        'finance.journal.view', 'finance.journal.create', 'finance.journal.reverse',
        'finance.period.manage', 'finance.report.view', 'finance.voucher.create',

        'reporting.dashboard.view', 'reporting.analytics.view',

        'records.view', 'records.manage',

        'production.view', 'production.recipe.manage', 'production.order.manage',
    ];

    /** `tenant_owner` is granted everything through Gate::before, not listed grants. */
    private const array ROLES = [
        'tenant_owner' => [],
        'finance_controller' => [
            'organization.company.view', 'finance.account.view', 'finance.account.manage', 'finance.journal.view',
            'finance.journal.create', 'finance.journal.reverse', 'finance.period.manage', 'finance.report.view', 'finance.voucher.create',
            'sales.sale.view', 'sales.customer.view', 'sales.payment.receive', 'inventory.stock.view', 'catalog.product.view',
            'purchasing.supplier.view', 'purchasing.order.view', 'purchasing.order.approve', 'purchasing.payment.create',
            'identity.audit.view', 'reporting.dashboard.view', 'reporting.analytics.view',
        ],
        'accountant' => [
            'organization.company.view', 'finance.account.view', 'finance.journal.view', 'finance.journal.create',
            'finance.voucher.create', 'finance.report.view', 'sales.sale.view', 'sales.customer.view', 'sales.payment.receive',
            'purchasing.supplier.view', 'purchasing.order.view', 'reporting.dashboard.view',
        ],
        'branch_manager' => [
            'catalog.product.view', 'catalog.product.manage', 'catalog.master.manage', 'catalog.pricing.manage',
            'inventory.stock.view', 'inventory.adjustment.create', 'inventory.transfer.manage', 'inventory.count.manage',
            'sales.sale.view', 'sales.sale.create', 'sales.sale.void', 'sales.price.override', 'sales.return.create',
            'sales.shift.manage', 'sales.customer.view', 'sales.customer.manage',
            'purchasing.supplier.view', 'purchasing.order.view', 'purchasing.order.create', 'purchasing.receipt.create',
            'reporting.dashboard.view', 'reporting.analytics.view', 'records.view', 'records.manage',
            'production.view', 'production.recipe.manage', 'production.order.manage',
        ],
        'inventory_controller' => [
            'catalog.product.view', 'inventory.stock.view', 'inventory.adjustment.create', 'inventory.transfer.manage',
            'inventory.count.manage', 'production.view', 'production.order.manage', 'purchasing.supplier.view', 'purchasing.order.view', 'purchasing.receipt.create',
        ],
        'procurement_manager' => [
            'catalog.product.view', 'inventory.stock.view', 'purchasing.supplier.view', 'purchasing.supplier.manage',
            'purchasing.order.view', 'purchasing.order.create', 'purchasing.order.approve', 'purchasing.receipt.create',
        ],
        'cashier' => [
            'catalog.product.view', 'sales.sale.create', 'sales.customer.view', 'records.view', 'records.manage',
        ],
        'auditor' => [
            'organization.company.view', 'identity.user.view', 'identity.audit.view', 'catalog.product.view',
            'inventory.stock.view', 'sales.sale.view', 'sales.customer.view', 'finance.account.view',
            'finance.journal.view', 'finance.report.view', 'purchasing.supplier.view', 'purchasing.order.view',
            'reporting.dashboard.view',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        foreach (self::ROLES as $name => $permissions) {
            Role::findOrCreate($name, self::GUARD)->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
