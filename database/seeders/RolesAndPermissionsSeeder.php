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

        'catalog.product.view', 'catalog.product.manage', 'catalog.master.manage',

        'inventory.stock.view', 'inventory.adjustment.create', 'inventory.transfer.manage',

        'sales.sale.view', 'sales.sale.create', 'sales.sale.void', 'sales.price.override',
        'sales.shift.manage', 'sales.customer.view', 'sales.customer.manage',

        'finance.account.view', 'finance.account.manage',
        'finance.journal.view', 'finance.journal.create', 'finance.journal.reverse',
        'finance.period.manage', 'finance.report.view',

        'reporting.dashboard.view',
    ];

    /** `tenant_owner` is granted everything through Gate::before, not listed grants. */
    private const array ROLES = [
        'tenant_owner' => [],
        'finance_controller' => [
            'organization.company.view', 'finance.account.view', 'finance.account.manage', 'finance.journal.view',
            'finance.journal.create', 'finance.journal.reverse', 'finance.period.manage', 'finance.report.view',
            'sales.sale.view', 'sales.customer.view', 'inventory.stock.view', 'catalog.product.view',
            'identity.audit.view', 'reporting.dashboard.view',
        ],
        'accountant' => [
            'organization.company.view', 'finance.account.view', 'finance.journal.view', 'finance.journal.create',
            'finance.report.view', 'sales.sale.view', 'sales.customer.view', 'reporting.dashboard.view',
        ],
        'branch_manager' => [
            'catalog.product.view', 'catalog.product.manage', 'catalog.master.manage',
            'inventory.stock.view', 'inventory.adjustment.create', 'inventory.transfer.manage',
            'sales.sale.view', 'sales.sale.create', 'sales.sale.void', 'sales.price.override',
            'sales.shift.manage', 'sales.customer.view', 'sales.customer.manage', 'reporting.dashboard.view',
        ],
        'inventory_controller' => [
            'catalog.product.view', 'inventory.stock.view', 'inventory.adjustment.create', 'inventory.transfer.manage',
        ],
        'cashier' => [
            'catalog.product.view', 'sales.sale.create', 'sales.customer.view',
        ],
        'auditor' => [
            'organization.company.view', 'identity.user.view', 'identity.audit.view', 'catalog.product.view',
            'inventory.stock.view', 'sales.sale.view', 'sales.customer.view', 'finance.account.view',
            'finance.journal.view', 'finance.report.view', 'reporting.dashboard.view',
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
