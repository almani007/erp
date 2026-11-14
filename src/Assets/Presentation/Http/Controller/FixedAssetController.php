<?php

declare(strict_types=1);

namespace Assets\Presentation\Http\Controller;

use Assets\Application\DepreciationCalculator;
use Assets\Application\ManageFixedAssets;
use Assets\Application\RunDepreciation;
use Assets\Infrastructure\Persistence\Eloquent\AssetCategory;
use Assets\Infrastructure\Persistence\Eloquent\DepreciationRun;
use Assets\Infrastructure\Persistence\Eloquent\FixedAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Shared\Infrastructure\Validation\TenantRule;

final class FixedAssetController
{
    // ── Categories ───────────────────────────────────────────────

    public function categories(): JsonResponse
    {
        return new JsonResponse(['data' => AssetCategory::query()->orderBy('name')->get()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate($this->categoryRules() + ['name' => ['required', 'string', 'max:100', TenantRule::unique('asset_categories', 'name')]]);

        return new JsonResponse(['data' => AssetCategory::query()->create($data + ['status' => 'active'])], 201);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $category = AssetCategory::query()->findOrFail($id);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100', TenantRule::unique('asset_categories', 'name', $category->id)], 'status' => ['sometimes', 'in:active,inactive']]
            + array_map(static fn ($r) => array_map(static fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->categoryRules()));
        $category->update($data);

        return new JsonResponse(['data' => $category]);
    }

    // ── Register ─────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $rows = FixedAsset::query()->with('category:id,name')->orderBy('asset_no')->limit(5000)->get()
            ->each(fn (FixedAsset $a) => $a->setAttribute('book_value', \Shared\Domain\Support\Decimal::sub((string) $a->cost, (string) $a->accumulated_depreciation, 2)));

        return new JsonResponse(['data' => $rows]);
    }

    public function show(string $id, DepreciationCalculator $calculator): JsonResponse
    {
        $asset = FixedAsset::query()->with('category:id,name')->findOrFail($id);

        return new JsonResponse(['data' => $asset->toArray() + [
            'book_value' => \Shared\Domain\Support\Decimal::sub((string) $asset->cost, (string) $asset->accumulated_depreciation, 2),
            'schedule' => $calculator->schedule($asset),
        ]]);
    }

    public function store(Request $request, ManageFixedAssets $action): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'uuid', TenantRule::exists('asset_categories')],
            'name' => ['required', 'string', 'max:200'],
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'custodian_id' => ['nullable', 'uuid', TenantRule::exists('employees')],
            'supplier_id' => ['nullable', 'uuid', TenantRule::exists('suppliers')],
            'purchase_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'in_service_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:purchase_date'],
            'cost' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'residual_value' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'lt:cost'],
            'method' => ['nullable', 'in:straight_line,reducing_balance'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'rate_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'opening_depreciation' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'depreciated_to' => ['nullable', 'date_format:Y-m-d'],
            'funding' => ['required', 'in:opening,cash,bank,supplier'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'custom' => ['nullable', 'array'],
        ]);

        $companyId = Company::query()->orderBy('created_at')->value('id');

        return new JsonResponse(['data' => $action->register($companyId, $data, $request->user()->id)], 201);
    }

    /** Details that do not change the books: location, custodian, serial, notes. */
    public function update(Request $request, string $id): JsonResponse
    {
        $asset = FixedAsset::query()->findOrFail($id);
        $asset->update($request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'custodian_id' => ['nullable', 'uuid', TenantRule::exists('employees')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'custom' => ['nullable', 'array'],
        ]));

        return new JsonResponse(['data' => $asset]);
    }

    public function dispose(Request $request, string $id, ManageFixedAssets $action): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'proceeds' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'received_by' => ['required', 'in:cash,bank'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return new JsonResponse(['data' => $action->dispose($id, $data['date'], (string) $data['proceeds'], $data['received_by'], $data['note'] ?? null)]);
    }

    // ── Depreciation ─────────────────────────────────────────────

    public function runs(): JsonResponse
    {
        return new JsonResponse(['data' => DepreciationRun::query()->orderByDesc('period')->orderByDesc('created_at')->limit(240)->get()]);
    }

    public function preview(Request $request, RunDepreciation $action): JsonResponse
    {
        $period = $request->validate(['period' => ['required', 'date_format:Y-m']])['period'];
        $companyId = Company::query()->orderBy('created_at')->value('id');

        return new JsonResponse(['data' => array_map(static fn (array $c): array => [
            'asset_id' => $c['asset']->id, 'asset_no' => $c['asset']->asset_no, 'name' => $c['asset']->name,
            'months' => $c['months'], 'amount' => $c['amount'],
        ], $action->preview($companyId, $period))]);
    }

    public function run(Request $request, RunDepreciation $action): JsonResponse
    {
        $period = $request->validate(['period' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')]])['period'];
        $companyId = Company::query()->orderBy('created_at')->value('id');

        return new JsonResponse(['data' => $action->run($companyId, $period, $request->user()->id)], 201);
    }

    public function reverse(Request $request, string $runId, RunDepreciation $action): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];

        return new JsonResponse(['data' => $action->reverse($runId, $reason)]);
    }

    /** @return array<string, list<mixed>> */
    private function categoryRules(): array
    {
        return [
            'method' => ['required', 'in:straight_line,reducing_balance'],
            'useful_life_months' => ['nullable', 'required_if:method,straight_line', 'integer', 'min:1', 'max:1200'],
            'rate_percent' => ['nullable', 'required_if:method,reducing_balance', 'numeric', 'gt:0', 'max:100'],
            'residual_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
