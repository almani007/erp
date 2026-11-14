<?php

declare(strict_types=1);

namespace Workflow\Application;

use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\TenantContext;

/**
 * Morning alerts under the bell, for every tenant:
 *   low stock (below reorder level)   → inventory controller, branch manager
 *   batches expiring within 30 days   → inventory controller
 *   FBR submissions that failed       → owner
 *   invoices overdue                  → finance controller
 */
final class DailyAlerts
{
    public function __construct(private readonly TenantContext $tenant, private readonly Notifier $notifier) {}

    public function run(): int
    {
        $sent = 0;
        foreach (DB::table('tenants')->where('status', 'active')->pluck('id') as $tenantId) {
            $this->tenant->set($tenantId);

            $low = DB::query()->fromSub(DB::table('products as p')->leftJoin('stock_balances as b', 'b.product_id', '=', 'p.id')
                ->where('p.tenant_id', $tenantId)->where('p.status', 'active')->where('p.reorder_level', '>', 0)
                ->groupBy('p.id', 'p.reorder_level')->havingRaw('COALESCE(sum(b.on_hand), 0) < p.reorder_level')->select('p.id'), 'x')->count();
            if ($low > 0) {
                foreach (['inventory_controller', 'branch_manager'] as $role) {
                    $this->notifier->toRole($role, 'stock', "{$low} products are below their reorder level", 'Suggested orders are ready to review.', '/reorder');
                }
                $sent++;
            }

            $expiring = DB::table('stock_lots')->where('tenant_id', $tenantId)->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')->where('expiry_date', '<=', today()->addDays(30))->count();
            if ($expiring > 0) {
                $this->notifier->toRole('inventory_controller', 'stock', "{$expiring} batches expire within 30 days", null, '/expiry');
                $sent++;
            }

            $failed = DB::table('fiscal_submissions')->where('tenant_id', $tenantId)->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count();
            if ($failed > 0) {
                $this->notifier->toRole('tenant_owner', 'fbr', "{$failed} sales could not be reported to FBR", 'Check the error and retry.', '/integrations');
                $sent++;
            }

            $overdue = DB::table('sales')->where('tenant_id', $tenantId)->where('status', 'completed')->where('balance_due', '>', 0)
                ->where('due_date', '<', today())->selectRaw('count(*) as c, COALESCE(sum(balance_due), 0) as s')->first();
            if ((int) $overdue->c > 0) {
                $this->notifier->toRole('finance_controller', 'finance', "{$overdue->c} invoices are overdue", 'Rs '.number_format((float) $overdue->s, 2).' outstanding.', '/receivables');
                $sent++;
            }
        }

        return $sent;
    }
}
