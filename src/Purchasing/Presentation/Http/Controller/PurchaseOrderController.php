<?php

declare(strict_types=1);

namespace Purchasing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Organization\Domain\Contract\BranchContext;
use Organization\Domain\Exception\BranchAccessDenied;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Purchasing\Application\ManagePurchaseOrder;
use Purchasing\Application\ReceiveGoods;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrder;
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
            'items.product:id,sku,name', 'supplier:id,name,supplier_code', 'receipts:id,purchase_order_id,grn_no,grand_total,received_at,supplier_invoice_no',
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
        ]);

        $receipt = $action->execute($this->find($orderId, $branches)->id, $data['lines'], $data['supplier_invoice_no'] ?? null, $request->user()->id);

        return new JsonResponse(['data' => $receipt], 201);
    }

    private function find(string $orderId, BranchContext $branches): PurchaseOrder
    {
        $order = PurchaseOrder::findOrFail($orderId);
        abort_unless($branches->canAccess($order->branch_id), 404);

        return $order;
    }
}
