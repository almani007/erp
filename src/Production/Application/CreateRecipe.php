<?php

declare(strict_types=1);

namespace Production\Application;

use Illuminate\Support\Facades\DB;
use Production\Infrastructure\Persistence\Eloquent\Recipe;
use Production\Infrastructure\Persistence\Eloquent\RecipeVersion;

final class CreateRecipe
{
    public function handle(array $data): Recipe
    {
        return DB::transaction(function () use ($data): Recipe {
            $recipe = Recipe::create([
                'tenant_id' => $data['tenant_id'],
                'company_id' => $data['company_id'],
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'status' => $data['status'] ?? 'draft',
            ]);

            if (! empty($data['components'])) {
                $version = RecipeVersion::create([
                    'recipe_id' => $recipe->id,
                    'version' => 1,
                    'effective_from' => $data['effective_from'] ?? now(),
                    'expected_yield' => $data['expected_yield'] ?? '1.000000',
                    'expected_wastage_pct' => $data['expected_wastage_pct'] ?? '0.0000',
                    'status' => 'active',
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($data['components'] as $component) {
                    $version->components()->create([
                        'product_id' => $component['product_id'],
                        'qty' => $component['qty'],
                        'unit_id' => $component['unit_id'],
                        'is_critical' => $component['is_critical'] ?? true,
                    ]);
                }
            }

            return $recipe->load('versions.components');
        });
    }
}
