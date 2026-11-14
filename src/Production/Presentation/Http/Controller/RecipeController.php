<?php

declare(strict_types=1);

namespace Production\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Production\Application\SaveRecipe;
use Production\Infrastructure\Persistence\Eloquent\Recipe;
use Shared\Infrastructure\Validation\TenantRule;

final class RecipeController
{
    public function index(Request $request): JsonResponse
    {
        $rows = Recipe::query()
            ->with(['product:id,sku,name,sale_price', 'items.product:id,sku,name,cost_price'])
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('status', 'active'))
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function store(Request $request, SaveRecipe $action): JsonResponse
    {
        return new JsonResponse(['data' => $action->execute($this->validated($request))], 201);
    }

    /** Saving again makes a new version; the old one is kept for past production orders. */
    public function update(Request $request, string $recipeId, SaveRecipe $action): JsonResponse
    {
        $existing = Recipe::query()->where('status', 'active')->findOrFail($recipeId);

        return new JsonResponse(['data' => $action->execute($this->validated($request), $existing)]);
    }

    public function destroy(string $recipeId): JsonResponse
    {
        Recipe::query()->findOrFail($recipeId)->update(['status' => 'inactive']);

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid', TenantRule::exists('products')],
            'yield_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'consume_on_sale' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'uuid', 'distinct', TenantRule::exists('products')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'items.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $data['yield_quantity'] = (string) $data['yield_quantity'];
        foreach ($data['items'] as $i => $item) {
            $data['items'][$i]['quantity'] = (string) $item['quantity'];
            $data['items'][$i]['wastage_percent'] = (string) ($item['wastage_percent'] ?? '0');
        }

        return $data;
    }
}
