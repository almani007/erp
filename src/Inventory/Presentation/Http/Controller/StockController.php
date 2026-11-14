<?php

declare(strict_types=1);

namespace Inventory\Presentation\Http\Controller;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Domain\Contract\BranchContext;
use Shared\Domain\Contract\TenantContext;

final class StockController
{
    public function balances(Request $request, TenantContext $tenant, BranchContext $branches): JsonResponse
    {
        $q = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
            'low_stock' => ['nullable', 'boolean'],
        ]);

        $rows = $this->scoped(DB::table('stock_balances as sb'), 'sb', $tenant, $branches)
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->when($q['warehouse_id'] ?? null, fn (Builder $b, $id) => $b->where('sb.warehouse_id', $id))
            ->when($q['q'] ?? null, function (Builder $b, string $term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $b->where(fn (Builder $w) => $w->where('p.name', 'ilike', $like)->orWhere('p.sku', 'ilike', $like));
            })
            ->when($request->boolean('low_stock'), fn (Builder $b) => $b->whereColumn('sb.on_hand', '<=', 'p.reorder_level'))
            ->orderBy('p.name')
            ->limit(2000)
            ->get([
                'sb.id', 'sb.warehouse_id', 'w.name as warehouse_name', 'sb.product_id', 'p.sku', 'p.name as product_name',
                'sb.on_hand', 'sb.reserved', 'sb.avg_cost', 'p.reorder_level',
                DB::raw('(sb.on_hand - sb.reserved) as available'),
                DB::raw('ROUND(sb.on_hand * sb.avg_cost, 2) as stock_value'),
            ]);

        return new JsonResponse(['data' => $rows]);
    }

    public function movements(Request $request, TenantContext $tenant, BranchContext $branches): JsonResponse
    {
        $q = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $rows = $this->scoped(DB::table('stock_movements as sm'), 'sm', $tenant, $branches)
            ->join('products as p', 'p.id', '=', 'sm.product_id')
            ->when($q['warehouse_id'] ?? null, fn (Builder $b, $id) => $b->where('sm.warehouse_id', $id))
            ->when($q['product_id'] ?? null, fn (Builder $b, $id) => $b->where('sm.product_id', $id))
            ->orderByDesc('sm.id')
            ->limit($q['limit'] ?? 300)
            ->get([
                'sm.id', 'sm.occurred_at', 'sm.movement_type', 'w.name as warehouse_name', 'p.sku', 'p.name as product_name',
                'sm.qty_delta', 'sm.unit_cost', 'sm.balance_after', 'sm.source_type', 'sm.source_id', 'sm.note',
            ]);

        return new JsonResponse(['data' => $rows]);
    }

    /**
     * Batches in stock, soonest expiry first. `within_days` narrows it to
     * what has expired or will expire within that many days.
     */
    public function lots(Request $request, TenantContext $tenant, BranchContext $branches): JsonResponse
    {
        $q = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'within_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        $rows = $this->scoped(DB::table('stock_lots as sl'), 'sl', $tenant, $branches)
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->where('sl.quantity', '>', 0)
            ->when($q['warehouse_id'] ?? null, fn (Builder $b, $id) => $b->where('sl.warehouse_id', $id))
            ->when(isset($q['within_days']), fn (Builder $b) => $b->whereNotNull('sl.expiry_date')
                ->where('sl.expiry_date', '<=', now()->addDays((int) $q['within_days'])->toDateString()))
            ->orderByRaw('sl.expiry_date ASC NULLS LAST')
            ->limit(2000)
            ->get([
                'sl.id', 'p.sku', 'p.name as product_name', 'w.name as warehouse_name', 'sl.batch_no', 'sl.expiry_date', 'sl.quantity',
                DB::raw("CASE WHEN sl.expiry_date IS NULL THEN NULL ELSE (sl.expiry_date - CURRENT_DATE) END AS days_left"),
            ]);

        return new JsonResponse(['data' => $rows]);
    }

    /** Constrain a raw stock query to the tenant and to branches the user may see. */
    private function scoped(Builder $query, string $alias, TenantContext $tenant, BranchContext $branches): Builder
    {
        $allowed = $branches->allowedBranchIds();

        return $query
            ->join('warehouses as w', 'w.id', '=', $alias.'.warehouse_id')
            ->where($alias.'.tenant_id', $tenant->requireId())
            ->when($allowed !== null, fn (Builder $b) => $b->whereIn('w.branch_id', $allowed));
    }
}
