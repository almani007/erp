<?php

declare(strict_types=1);

namespace Inventory\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inventory\Application\PostStockAdjustment;
use Inventory\Infrastructure\Persistence\Eloquent\StockAdjustment;
use Organization\Domain\Contract\BranchContext;
use Shared\Infrastructure\Validation\TenantRule;

final class StockAdjustmentController
{
    use ResolvesWarehouse;

    public function index(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        $rows = StockAdjustment::query()
            ->with('warehouse:id,name')
            ->when($allowed !== null, fn ($b) => $b->whereIn('branch_id', $allowed))
            ->orderByDesc('posted_at')
            ->limit(300)
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function show(string $adjustmentId, BranchContext $branches): JsonResponse
    {
        $adjustment = StockAdjustment::with(['warehouse:id,name', 'lines.product:id,sku,name'])->findOrFail($adjustmentId);
        abort_unless($branches->canAccess($adjustment->branch_id), 404);

        return new JsonResponse(['data' => $adjustment]);
    }

    public function store(Request $request, PostStockAdjustment $action, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'reason_code' => ['required', Rule::in(PostStockAdjustment::REASONS)],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid', 'distinct', TenantRule::exists('products')],
            'lines.*.qty_delta' => ['required', 'numeric', 'not_in:0', 'decimal:0,6'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $warehouse = $this->warehouse($data['warehouse_id'], $branches);
        $costs = \Catalog\Infrastructure\Persistence\Eloquent\Product::query()->whereIn('id', array_column($data['lines'], 'product_id'))->pluck('cost_price', 'id');
        $value = 0.0;
        foreach ($data['lines'] as $l) {
            $value += abs((float) $l['qty_delta']) * (float) ($l['unit_cost'] ?? $costs[$l['product_id']] ?? 0);
        }
        $pending = app(\Workflow\Application\Approvals::class)->gate('stock_adjustment', number_format($value, 2, '.', ''),
            "Stock adjustment ({$data['reason_code']}) in {$warehouse->name}, ".count($data['lines']).' items',
            ['warehouse_id' => $warehouse->id, 'reason_code' => $data['reason_code'], 'notes' => $data['notes'] ?? null, 'lines' => $data['lines']], $warehouse->branch_id);
        if ($pending !== null) {
            return new JsonResponse(['data' => ['status' => 'pending_approval', 'approval_request' => $pending]], 202);
        }

        $adjustment = $action->execute(
            $warehouse,
            $data['reason_code'],
            $data['notes'] ?? null,
            $data['lines'],
        );

        return new JsonResponse(['data' => $adjustment], 201);
    }
}
