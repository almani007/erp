<?php

declare(strict_types=1);

namespace Inventory\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inventory\Application\TransferStock;
use Inventory\Infrastructure\Persistence\Eloquent\StockTransfer;
use Organization\Domain\Contract\BranchContext;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Shared\Infrastructure\Validation\TenantRule;

final class StockTransferController
{
    use ResolvesWarehouse;

    public function index(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        $rows = StockTransfer::query()
            ->with(['source:id,name,branch_id', 'destination:id,name,branch_id', 'lines.product:id,sku,name'])
            ->when($allowed !== null, function ($b) use ($allowed): void {
                $visible = Warehouse::query()->whereIn('branch_id', $allowed)->pluck('id');
                $b->where(fn ($w) => $w->whereIn('source_warehouse_id', $visible)->orWhereIn('destination_warehouse_id', $visible));
            })
            ->orderByDesc('dispatched_at')
            ->limit(300)
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function store(Request $request, TransferStock $action, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'source_warehouse_id' => ['required', 'uuid'],
            'destination_warehouse_id' => ['required', 'uuid', 'different:source_warehouse_id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid', 'distinct', TenantRule::exists('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
        ]);

        // Sending requires access to the source; the destination only has to exist in the tenant.
        $transfer = $action->dispatch(
            $this->warehouse($data['source_warehouse_id'], $branches),
            Warehouse::query()->where('status', 'active')->findOrFail($data['destination_warehouse_id']),
            $data['lines'],
            $data['notes'] ?? null,
        );

        return new JsonResponse(['data' => $transfer], 201);
    }

    public function receive(string $transferId, TransferStock $action, BranchContext $branches): JsonResponse
    {
        $transfer = StockTransfer::findOrFail($transferId);
        $this->warehouse($transfer->destination_warehouse_id, $branches); // receiver must work at the destination

        return new JsonResponse(['data' => $action->receive($transfer->id)]);
    }
}
