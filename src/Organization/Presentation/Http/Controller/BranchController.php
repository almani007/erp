<?php

declare(strict_types=1);

namespace Organization\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Organization\Application\CreateBranch;
use Organization\Domain\Contract\BranchContext;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Shared\Infrastructure\Validation\TenantRule;

final class BranchController
{
    /** Branches the current user may work in (drives the branch selector). */
    public function index(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        $rows = Branch::query()
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('name')
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function show(string $branchId, BranchContext $branches): JsonResponse
    {
        abort_unless($branches->canAccess($branchId), 404);

        return new JsonResponse(['data' => Branch::with(['company', 'warehouses'])->findOrFail($branchId)]);
    }

    public function store(Request $request, CreateBranch $action): JsonResponse
    {
        app(\Saas\Application\Entitlements::class)->assertWithinLimit('max_branches', \Organization\Infrastructure\Persistence\Eloquent\Branch::query()->count(), 'branches');

        $validated = $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'code' => [
                'required', 'string', 'max:30', 'alpha_dash',
                Rule::unique('branches', 'code')->where('company_id', $request->input('company_id')),
            ],
            'name' => ['required', 'string', 'max:150'],
            'timezone' => ['required', 'timezone'],
            'address' => ['nullable', 'array'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
        ]);

        return new JsonResponse(['data' => $action->execute($validated)], 201);
    }

    public function update(Request $request, string $branchId): JsonResponse
    {
        $branch = Branch::findOrFail($branchId);

        $branch->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'timezone' => ['sometimes', 'timezone'],
            'address' => ['nullable', 'array'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'status' => ['sometimes', 'in:active,inactive,closed'],
        ]));

        return new JsonResponse(['data' => $branch->fresh()]);
    }
}
