<?php

declare(strict_types=1);

namespace Reporting\Presentation\Http\Controller;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Organization\Domain\Contract\BranchContext;
use Shared\Domain\Contract\TenantContext;

/**
 * Operational summary for the home screen. Figures cover the branches the
 * caller may see (narrowed to the active branch when one is selected) and
 * are computed live — fine at pilot volumes; read models come with BI.
 */
final class DashboardController
{
    public function summary(TenantContext $tenant, BranchContext $branches): JsonResponse
    {
        $tenantId = $tenant->requireId();
        $active = $branches->activeBranchId();
        $allowed = $active !== null ? [$active] : $branches->allowedBranchIds();

        $sales = fn (): Builder => DB::table('sales')
            ->where('tenant_id', $tenantId)->where('status', 'completed')
            ->when($allowed !== null, fn (Builder $b) => $b->whereIn('branch_id', $allowed));

        $today = $sales()->where('sold_at', '>=', now()->startOfDay())
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS total, COALESCE(SUM(grand_total - tax_total - cost_total), 0) AS margin')
            ->first();

        $daily = $sales()->where('sold_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(sold_at) AS day, COUNT(*) AS n, SUM(grand_total) AS total')
            ->groupByRaw('DATE(sold_at)')->orderBy('day')->get();

        $topProducts = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)->where('s.status', 'completed')
            ->where('s.sold_at', '>=', now()->subDays(29)->startOfDay())
            ->when($allowed !== null, fn (Builder $b) => $b->whereIn('s.branch_id', $allowed))
            ->groupBy('si.product_id', 'si.product_name')
            ->selectRaw('si.product_name AS name, SUM(si.quantity) AS quantity, SUM(si.line_total) AS total')
            ->orderByDesc('total')->limit(5)->get();

        $stock = DB::table('stock_balances as sb')
            ->join('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->where('sb.tenant_id', $tenantId)
            ->when($allowed !== null, fn (Builder $b) => $b->whereIn('w.branch_id', $allowed))
            ->selectRaw('COALESCE(SUM(ROUND(sb.on_hand * sb.avg_cost, 2)), 0) AS value, COUNT(*) FILTER (WHERE sb.on_hand <= p.reorder_level) AS low')
            ->first();

        $paymentMix = DB::table('sale_tenders as t')
            ->join('sales as s', 's.id', '=', 't.sale_id')
            ->where('s.tenant_id', $tenantId)->where('s.status', 'completed')
            ->where('s.sold_at', '>=', now()->subDays(29)->startOfDay())
            ->when($allowed !== null, fn (Builder $b) => $b->whereIn('s.branch_id', $allowed))
            ->groupBy('t.method')
            ->selectRaw('t.method, SUM(t.amount) AS total')
            ->orderByDesc('total')->get();

        $lowStock = DB::table('stock_balances as sb')
            ->join('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->where('sb.tenant_id', $tenantId)
            ->whereColumn('sb.on_hand', '<=', 'p.reorder_level')
            ->when($allowed !== null, fn (Builder $b) => $b->whereIn('w.branch_id', $allowed))
            ->orderBy('sb.on_hand')->limit(8)
            ->get(['p.sku', 'p.name', 'w.name as warehouse', 'sb.on_hand', 'p.reorder_level']);

        $recentSales = $sales()->orderByDesc('sold_at')->limit(8)->get(['id', 'sale_no', 'sold_at', 'grand_total']);

        $month = $sales()->where('sold_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS total')->first();

        return new JsonResponse(['data' => [
            'sales_today' => ['count' => (int) $today->n, 'total' => (string) $today->total, 'gross_margin' => (string) $today->margin],
            'sales_this_month' => ['count' => (int) $month->n, 'total' => (string) $month->total],
            'sales_last_14_days' => $daily,
            'top_products_30_days' => $topProducts,
            'payment_mix_30_days' => $paymentMix,
            'low_stock' => $lowStock,
            'recent_sales' => $recentSales,
            'stock' => ['value' => (string) $stock->value, 'low_stock_items' => (int) $stock->low],
            'receivables' => (string) DB::table('customers')->where('tenant_id', $tenantId)->sum('balance'),
            'payables' => (string) DB::table('suppliers')->where('tenant_id', $tenantId)->sum('balance'),
            'open_purchase_orders' => DB::table('purchase_orders')->where('tenant_id', $tenantId)
                ->whereIn('status', ['draft', 'approved', 'partially_received'])
                ->when($allowed !== null, fn (Builder $b) => $b->whereIn('branch_id', $allowed))->count(),
        ]]);
    }
}
