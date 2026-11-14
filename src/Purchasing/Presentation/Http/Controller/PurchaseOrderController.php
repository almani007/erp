<?php

declare(strict_types=1);

namespace Purchasing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Domain\Contract\BranchContext;
use Organization\Domain\Exception\BranchAccessDenied;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Purchasing\Application\ManagePurchaseOrder;
use Purchasing\Application\ReceiveGoods;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrder;
use Shared\Domain\Contract\TenantContext;
use Shared\Infrastructure\Validation\TenantRule;

final class PurchaseOrderController
{
    public function index(Request $request, BranchContext $branches): JsonResponse
    {
        $q = $request->validate(['status' => ['nullable', 'string', 'max:30']]);
        $allowed = $branches->allowedBranchIds();

        $rows = PurchaseOrder::query()
            ->with('supplier:id,name,supplier_code')
            ->when($allowed !== null, fn ($b) => $b->whereIn('branch_id', $allowed))
            ->when($q['status'] ?? null, fn ($b, $s) => $b->where('status', $s))
            ->orderByDesc('order_date')->orderByDesc('po_no')
            ->limit(500)
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function show(string $orderId, BranchContext $branches): JsonResponse
    {
        return new JsonResponse(['data' => $this->find($orderId, $branches)->load([
            'items.product:id,sku,name,track_batch,track_expiry', 'supplier:id,name,supplier_code', 'receipts:id,purchase_order_id,grn_no,grand_total,received_at,supplier_invoice_no',
        ])]);
    }

    public function store(Request $request, ManagePurchaseOrder $orders, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'uuid', TenantRule::exists('suppliers')],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid', 'distinct', TenantRule::exists('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $warehouse = Warehouse::query()->where('status', 'active')->findOrFail($data['warehouse_id']);
        if (! $branches->canAccess($warehouse->branch_id)) {
            throw BranchAccessDenied::forBranch($warehouse->branch_id);
        }

        return new JsonResponse(['data' => $orders->create($warehouse, $data, $data['lines'], $request->user()->id)], 201);
    }

    public function approve(Request $request, string $orderId, ManagePurchaseOrder $orders, BranchContext $branches): JsonResponse
    {
        return new JsonResponse(['data' => $orders->approve($this->find($orderId, $branches)->id, $request->user())]);
    }

    public function cancel(Request $request, string $orderId, ManagePurchaseOrder $orders, BranchContext $branches): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new JsonResponse(['data' => $orders->cancel($this->find($orderId, $branches)->id, $data['reason'])]);
    }

    public function receive(Request $request, string $orderId, ReceiveGoods $action, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'supplier_invoice_no' => ['nullable', 'string', 'max:80'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.purchase_order_item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ]);

        $receipt = $action->execute($this->find($orderId, $branches)->id, $data['lines'], $data['supplier_invoice_no'] ?? null, $request->user()->id);

        return new JsonResponse(['data' => $receipt], 201);
    }

    /**
     * Products at or below their reorder level in one warehouse, with a
     * suggested order quantity: enough to reach twice the reorder level,
     * less what is already on order for that warehouse.
     */
    public function reorderSuggestions(Request $request, BranchContext $branches, TenantContext $tenant): JsonResponse
    {
        $q = $request->validate(['warehouse_id' => ['required', 'uuid']]);

        $warehouse = Warehouse::findOrFail($q['warehouse_id']);
        if (! $branches->canAccess($warehouse->branch_id)) {
            throw BranchAccessDenied::forBranch($warehouse->branch_id);
        }

        $onOrder = DB::table('purchase_order_items as i')
            ->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('o.warehouse_id', $warehouse->id)
            ->whereIn('o.status', ['draft', 'approved', 'partially_received'])
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, SUM(i.quantity - i.received_quantity) AS qty');

        $rows = DB::table('products as p')
            ->leftJoin('stock_balances as sb', fn ($j) => $j->on('sb.product_id', '=', 'p.id')->where('sb.warehouse_id', $warehouse->id))
            ->leftJoinSub($onOrder, 'oo', 'oo.product_id', '=', 'p.id')
            ->where('p.tenant_id', $tenant->requireId())
            ->where('p.status', 'active')
            ->whereIn('p.product_type', ['goods', 'composite'])
            ->where('p.reorder_level', '>', 0)
            ->whereRaw('COALESCE(sb.on_hand, 0) + COALESCE(oo.qty, 0) <= p.reorder_level')
            ->orderBy('p.name')
            ->limit(1000)
            ->get([
                'p.id', 'p.sku', 'p.name', 'p.cost_price', 'p.reorder_level',
                DB::raw('COALESCE(sb.on_hand, 0) AS on_hand'),
                DB::raw('COALESCE(oo.qty, 0) AS on_order'),
                DB::raw('GREATEST(p.reorder_level * 2 - COALESCE(sb.on_hand, 0) - COALESCE(oo.qty, 0), 0) AS suggested_qty'),
            ]);

        return new JsonResponse(['data' => $rows]);
    }

    private function find(string $orderId, BranchContext $branches): PurchaseOrder
    {
        $order = PurchaseOrder::findOrFail($orderId);
        abort_unless($branches->canAccess($order->branch_id), 404);

        return $order;
    }
}
