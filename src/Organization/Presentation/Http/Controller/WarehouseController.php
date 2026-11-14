<?php

declare(strict_types=1);

namespace Organization\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Organization\Application\CreateWarehouse;
use Organization\Domain\Contract\BranchContext;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Shared\Infrastructure\Validation\TenantRule;

final class WarehouseController
{
    public function index(Request $request, BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        $rows = Warehouse::query()
            ->with('branch:id,name,code')
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->when($request->query('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->orderBy('name')
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function store(Request $request, CreateWarehouse $action): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'uuid', TenantRule::exists('branches')],
            'code' => [
                'required', 'string', 'max:30', 'alpha_dash',
                Rule::unique('warehouses', 'code')->where('branch_id', $request->input('branch_id')),
            ],
            'name' => ['required', 'string', 'max:150'],
        ]);

        // The company is taken from the branch so the two can never disagree.
        $validated['company_id'] = Branch::findOrFail($validated['branch_id'])->company_id;

        return new JsonResponse(['data' => $action->execute($validated)], 201);
    }

    public function update(Request $request, string $warehouseId): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($warehouseId);

        $warehouse->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return new JsonResponse(['data' => $warehouse->fresh()]);
    }
}
