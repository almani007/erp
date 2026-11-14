<?php

declare(strict_types=1);

namespace Production\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inventory\Presentation\Http\Controller\ResolvesWarehouse;
use Organization\Domain\Contract\BranchContext;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Production\Application\ManageProduction;
use Production\Infrastructure\Persistence\Eloquent\ProductionOrder;
use Production\Infrastructure\Persistence\Eloquent\Recipe;
use Shared\Infrastructure\Validation\TenantRule;

final class ProductionOrderController
{
    use ResolvesWarehouse;

    public function index(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        $rows = ProductionOrder::query()
            ->with(['product:id,sku,name', 'warehouse:id,name', 'items.product:id,sku,name'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('warehouse_id', Warehouse::query()->whereIn('branch_id', $allowed)->select('id')))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function store(Request $request, ManageProduction $action, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'recipe_id' => ['required', 'uuid', TenantRule::exists('recipes')],
            'warehouse_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'notes' => ['nullable', 'string', 'max:500'],
            'with_sub_assemblies' => ['sometimes', 'boolean'],
        ]);

        $order = $action->plan(
            Recipe::query()->findOrFail($data['recipe_id']),
            $this->warehouse($data['warehouse_id'], $branches),
            (string) $data['quantity'],
            $data['notes'] ?? null,
            (bool) ($data['with_sub_assemblies'] ?? false),
        );

        return new JsonResponse(['data' => $order], 201);
    }

    public function complete(Request $request, string $orderId, ManageProduction $action, BranchContext $branches): JsonResponse
    {
        $order = ProductionOrder::query()->findOrFail($orderId);
        $this->warehouse($order->warehouse_id, $branches);

        $data = $request->validate([
            'produced_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'extra_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'batch_no' => ['nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'used' => ['sometimes', 'array'],
            'used.*.product_id' => ['required', 'uuid'],
            'used.*.quantity' => ['required', 'numeric', 'min:0', 'decimal:0,6'],
        ]);

        $used = [];
        foreach ($data['used'] ?? [] as $line) {
            $used[$line['product_id']] = (string) $line['quantity'];
        }

        return new JsonResponse(['data' => $action->complete(
            $order->id, (string) $data['produced_quantity'], (string) ($data['extra_cost'] ?? '0'), $used,
            $data['batch_no'] ?? null, $data['expiry_date'] ?? null,
        )]);
    }

    public function cancel(string $orderId, ManageProduction $action, BranchContext $branches): JsonResponse
    {
        $order = ProductionOrder::query()->findOrFail($orderId);
        $this->warehouse($order->warehouse_id, $branches);

        return new JsonResponse(['data' => $action->cancel($order->id)]);
    }
}
