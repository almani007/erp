<?php

declare(strict_types=1);

namespace Production\Application;

use Illuminate\Support\Facades\DB;
use Inventory\Domain\Enum\MovementType;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Domain\StockPosting;
use Production\Infrastructure\Persistence\Eloquent\Recipe;
use Shared\Domain\Support\Decimal;

/**
 * Selling a recipe item (a dish, a drink, a juice) takes its ingredients
 * out of the selling warehouse. Used by the sales module.
 */
final class RecipeConsumption
{
    /** @var array<string, Recipe|false> */
    private array $cache = [];

    public function __construct(private readonly StockLedger $ledger) {}

    public function recipeFor(string $productId): ?Recipe
    {
        if (! array_key_exists($productId, $this->cache)) {
            $this->cache[$productId] = Recipe::query()->with('items')
                ->where('product_id', $productId)->where('status', 'active')->where('consume_on_sale', true)
                ->first() ?? false;
        }

        return $this->cache[$productId] ?: null;
    }

    /**
     * @return string|null cost of the ingredients per one unit sold, or null when the product has no such recipe
     */
    public function consume(string $companyId, string $warehouseId, string $productId, string $quantity, string $saleId): ?string
    {
        $recipe = $this->recipeFor($productId);
        if ($recipe === null) {
            return null;
        }

        $cost = '0';
        foreach (SaveRecipe::requirements($recipe, $quantity) as $ingredientId => $needed) {
            if (! Decimal::isPositive($needed)) {
                continue;
            }
            $unitCost = $this->ledger->issue(new StockPosting(
                $companyId, $warehouseId, $ingredientId, $needed, MovementType::SaleRecipe, 'sale', $saleId,
                note: 'recipe:'.$productId,
            ));
            $cost = Decimal::add($cost, Decimal::mul($needed, $unitCost, 4));
        }

        return Decimal::div($cost, $quantity, Decimal::MONEY_SCALE);
    }

    /** A voided sale puts every ingredient it took back, at the cost it left at. */
    public function restoreForVoid(string $companyId, string $warehouseId, string $saleId, string $reason): void
    {
        $taken = DB::table('stock_movements')
            ->where('source_type', 'sale')->where('source_id', $saleId)->where('movement_type', MovementType::SaleRecipe->value)
            ->orderBy('id')->get(['product_id', 'qty_delta', 'unit_cost']);

        foreach ($taken as $movement) {
            $this->ledger->receive(new StockPosting(
                $companyId, $warehouseId, $movement->product_id, Decimal::negate((string) $movement->qty_delta, Decimal::QTY_SCALE),
                MovementType::SaleRecipeVoid, 'sale', $saleId, 'void: '.$reason,
                lotsFromSourceType: 'sale', lotsFromSourceId: $saleId,
            ), (string) $movement->unit_cost);
        }
    }
}
