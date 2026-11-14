<?php

declare(strict_types=1);

namespace Production\Application;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Support\Facades\DB;
use Production\Infrastructure\Persistence\Eloquent\Recipe;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Support\Decimal;

/**
 * Creates or replaces a product's recipe (bill of materials).
 *
 * Two uses, chosen per recipe:
 *  - production (bakery, workshop, garment unit): a production order turns
 *    the materials into stock of the product at their combined cost;
 *  - consume on sale (restaurant, café, juice bar): the product holds no
 *    stock, and each sale takes its ingredients out instead.
 */
final class SaveRecipe
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array{product_id: string, yield_quantity: string, consume_on_sale: bool, notes?: string|null,
     *               items: list<array{product_id: string, quantity: string, wastage_percent?: string|null}>}  $data
     */
    public function execute(array $data, ?Recipe $existing = null): Recipe
    {
        $product = Product::query()->findOrFail($data['product_id']);

        if ($data['consume_on_sale'] && $product->product_type->tracksInventory()) {
            throw new \DomainException("{$product->name} keeps its own stock. Set its type to non-stock to take ingredients out when it is sold.");
        }
        if (! $data['consume_on_sale'] && ! $product->product_type->tracksInventory()) {
            throw new \DomainException("{$product->name} does not keep stock, so it cannot be produced into stock. Use \"take ingredients out when sold\" instead.");
        }
        if (! Decimal::isPositive((string) $data['yield_quantity'])) {
            throw new \DomainException('The recipe must make a positive quantity.');
        }

        $components = Product::query()->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
        foreach ($data['items'] as $item) {
            $component = $components[$item['product_id']] ?? null;
            if ($component === null) {
                throw new \DomainException('An ingredient no longer exists.');
            }
            if ($component->id === $product->id) {
                throw new \DomainException('A product cannot be an ingredient of itself.');
            }
            if (! $component->product_type->tracksInventory()) {
                throw new \DomainException("{$component->name} does not keep stock, so it cannot be an ingredient.");
            }
        }

        return DB::transaction(function () use ($data, $product, $existing): Recipe {
            // A changed recipe is a new version; production orders keep pointing at the old one.
            $existing?->update(['status' => 'inactive']);
            Recipe::query()->where('product_id', $product->id)->where('status', 'active')->update(['status' => 'inactive']);

            $recipe = Recipe::query()->create([
                'product_id' => $product->id,
                'yield_quantity' => $data['yield_quantity'],
                'consume_on_sale' => $data['consume_on_sale'],
                'notes' => $data['notes'] ?? null,
                'status' => 'active',
            ]);

            foreach ($data['items'] as $item) {
                $recipe->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'wastage_percent' => $item['wastage_percent'] ?? '0',
                ]);
            }

            $this->audit->record('production.recipe.saved', 'recipe', $recipe->id, null, [
                'product' => $product->sku, 'items' => count($data['items']), 'consume_on_sale' => $data['consume_on_sale'],
            ]);

            return $recipe->load(['product:id,sku,name', 'items.product:id,sku,name']);
        });
    }

    /**
     * Base-unit quantities of each ingredient needed to make `$quantity`
     * of the recipe's product, wastage included.
     *
     * @return array<string, string> product id => quantity
     */
    public static function requirements(Recipe $recipe, string $quantity): array
    {
        $factor = Decimal::div($quantity, (string) $recipe->yield_quantity, 10);
        $needs = [];

        foreach ($recipe->items as $item) {
            $withWastage = Decimal::mul((string) $item->quantity, Decimal::add('1', Decimal::div((string) $item->wastage_percent, '100', 10)), 10);
            $qty = Decimal::round(Decimal::mul($withWastage, $factor, 10), Decimal::QTY_SCALE);
            $needs[$item->product_id] = Decimal::add($needs[$item->product_id] ?? '0', $qty, Decimal::QTY_SCALE);
        }

        return $needs;
    }
}
