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
        'sales.order.view', 'sales.order.manage', 'sales.invoice.create', 'sales.receivable.view',
        'purchasing.bill.manage', 'purchasing.bill.override', 'purchasing.return.create', 'purchasing.payable.view',
        'assets.view', 'assets.manage', 'assets.dispose', 'assets.depreciation.run',
        'banking.view', 'banking.manage', 'banking.cheque.manage', 'banking.reconcile',
        'finance.budget.manage', 'finance.recurring.manage', 'finance.year.close',
        'restaurant.order.manage', 'restaurant.kitchen.view', 'restaurant.setup.manage',
        'bookings.manage', 'bookings.setup', 'rentals.manage',
        'integration.manage', 'integration.message.send', 'integration.import',
        'workflow.rules.manage',

        'purchasing.supplier.view', 'purchasing.supplier.manage', 'purchasing.order.view', 'purchasing.order.create',
        'purchasing.order.approve', 'purchasing.receipt.create', 'purchasing.payment.create',

        'finance.account.view', 'finance.account.manage',
        'finance.journal.view', 'finance.journal.create', 'finance.journal.reverse',
        'finance.period.manage', 'finance.report.view', 'finance.voucher.create',

        'reporting.dashboard.view', 'reporting.analytics.view',

        'records.view', 'records.manage',

        'production.view', 'production.recipe.manage', 'production.order.manage',

        'people.employee.view', 'people.employee.manage', 'people.attendance.manage',
        'people.payroll.manage', 'people.payroll.approve',
        'people.leave.request', 'people.leave.manage', 'people.leave.approve',
        'sales.giftcard.manage', 'sales.giftcard.promote',
        'finance.currency.manage', 'finance.fx.revalue',
    ];

    /** `tenant_owner` is granted everything through Gate::before, not listed grants. */
    private const array ROLES = [
        'tenant_owner' => [],
        'finance_controller' => [
            'finance.currency.manage', 'finance.fx.revalue', 'people.leave.request',
            'organization.company.view', 'finance.account.view', 'finance.account.manage', 'finance.journal.view',
            'finance.journal.create', 'finance.journal.reverse', 'finance.period.manage', 'finance.report.view', 'finance.voucher.create',
            'sales.sale.view', 'sales.customer.view', 'sales.payment.receive', 'sales.receivable.view', 'sales.order.view', 'inventory.stock.view', 'catalog.product.view',
            'purchasing.payable.view', 'purchasing.bill.override', 'purchasing.payment.create',
            'assets.view', 'assets.manage', 'assets.dispose', 'assets.depreciation.run',
            'banking.view', 'banking.manage', 'banking.cheque.manage', 'banking.reconcile', 'integration.message.send',
            'finance.budget.manage', 'finance.recurring.manage', 'finance.year.close',
            'purchasing.supplier.view', 'purchasing.order.view', 'purchasing.order.approve', 'purchasing.payment.create',
            'identity.audit.view', 'reporting.dashboard.view', 'reporting.analytics.view',
            'people.employee.view', 'people.payroll.approve',
        ],
        'accountant' => [
            'finance.currency.manage', 'people.leave.request',
            'organization.company.view', 'finance.account.view', 'finance.journal.view', 'finance.journal.create',
            'finance.voucher.create', 'finance.report.view', 'sales.sale.view', 'sales.customer.view', 'sales.payment.receive', 'sales.receivable.view', 'sales.order.view',
            'purchasing.supplier.view', 'purchasing.order.view', 'reporting.dashboard.view',
            'purchasing.bill.manage', 'purchasing.payable.view', 'assets.view', 'assets.manage',
            'banking.view', 'banking.cheque.manage', 'banking.reconcile', 'finance.recurring.manage',
            'people.employee.view', 'people.payroll.manage',
        ],
        'branch_manager' => [
            'people.leave.request', 'people.leave.approve', 'people.leave.manage', 'sales.giftcard.manage', 'sales.giftcard.promote',
            'integration.message.send', 'integration.import',
            'restaurant.order.manage', 'restaurant.kitchen.view', 'restaurant.setup.manage', 'bookings.manage', 'bookings.setup', 'rentals.manage',
            'catalog.product.view', 'catalog.product.manage', 'catalog.master.manage', 'catalog.pricing.manage',
            'inventory.stock.view', 'inventory.adjustment.create', 'inventory.transfer.manage', 'inventory.count.manage',
            'sales.sale.view', 'sales.sale.create', 'sales.sale.void', 'sales.price.override', 'sales.return.create',
            'sales.order.view', 'sales.order.manage', 'sales.invoice.create', 'sales.receivable.view',
            'sales.shift.manage', 'sales.customer.view', 'sales.customer.manage',
            'purchasing.supplier.view', 'purchasing.order.view', 'purchasing.order.create', 'purchasing.receipt.create',
            'reporting.dashboard.view', 'reporting.analytics.view', 'records.view', 'records.manage',
            'production.view', 'production.recipe.manage', 'production.order.manage',
            'people.employee.view', 'people.attendance.manage',
        ],
        'inventory_controller' => [
            'people.leave.request',
            'catalog.product.view', 'inventory.stock.view', 'inventory.adjustment.create', 'inventory.transfer.manage',
            'inventory.count.manage', 'production.view', 'production.order.manage', 'purchasing.supplier.view', 'purchasing.order.view', 'purchasing.receipt.create',
        ],
        'procurement_manager' => [
            'people.leave.request',
            'catalog.product.view', 'inventory.stock.view', 'purchasing.supplier.view', 'purchasing.supplier.manage',
            'purchasing.order.view', 'purchasing.order.create', 'purchasing.order.approve', 'purchasing.receipt.create',
            'purchasing.bill.manage', 'purchasing.return.create', 'purchasing.payable.view', 'finance.account.view',
        ],
        'cashier' => [
            'people.leave.request', 'sales.giftcard.manage',
            'catalog.product.view', 'sales.sale.create', 'sales.customer.view', 'records.view', 'records.manage',
            'restaurant.order.manage', 'bookings.manage',
        ],
        // Takes orders at tables and sends them to the kitchen; settling is the cashier's.
        'waiter' => ['catalog.product.view', 'restaurant.order.manage', 'people.leave.request'],
        // Kitchen display only.
        'kitchen' => ['restaurant.kitchen.view', 'people.leave.request'],
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
