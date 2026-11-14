<?php

declare(strict_types=1);

namespace Inventory\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inventory\Application\ConductStockCount;
use Inventory\Infrastructure\Persistence\Eloquent\StockCount;
use Organization\Domain\Contract\BranchContext;

final class StockCountController
{
    use ResolvesWarehouse;

    public function index(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        $rows = StockCount::query()
            ->with('warehouse:id,name')
            ->withCount(['lines', 'lines as counted_lines_count' => fn ($q) => $q->whereNotNull('counted_qty')])
            ->when($allowed !== null, fn ($b) => $b->whereIn('branch_id', $allowed))
            ->orderByDesc('started_at')
            ->limit(300)
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function show(string $countId, BranchContext $branches): JsonResponse
    {
        return new JsonResponse(['data' => $this->find($countId, $branches)->load(['warehouse:id,name', 'lines.product:id,sku,name'])]);
    }

    public function store(Request $request, ConductStockCount $counts, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $count = $counts->start($this->warehouse($data['warehouse_id'], $branches), $data['notes'] ?? null, $request->user()->id);

        return new JsonResponse(['data' => $count], 201);
    }

    public function enter(Request $request, string $countId, ConductStockCount $counts, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:5000'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.counted_qty' => ['nullable', 'numeric', 'min:0', 'decimal:0,6'],
        ]);

        $counts->enter($this->find($countId, $branches), $data['lines']);

        return new JsonResponse(['data' => ['saved' => count($data['lines'])]]);
    }

    public function post(Request $request, string $countId, ConductStockCount $counts, BranchContext $branches): JsonResponse
    {
        return new JsonResponse(['data' => $counts->post($this->find($countId, $branches), $request->user()->id)]);
    }

    public function cancel(string $countId, ConductStockCount $counts, BranchContext $branches): JsonResponse
    {
        return new JsonResponse(['data' => $counts->cancel($this->find($countId, $branches))]);
    }

    private function find(string $countId, BranchContext $branches): StockCount
    {
        $count = StockCount::findOrFail($countId);
        abort_unless($branches->canAccess($count->branch_id), 404);

        return $count;
    }
}
