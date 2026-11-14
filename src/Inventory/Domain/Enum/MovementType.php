<?php

declare(strict_types=1);

namespace Inventory\Domain\Enum;

enum MovementType: string
{
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Sale = 'sale';
    case SaleVoid = 'sale_void';
    case SaleReturn = 'sale_return';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case PurchaseReceipt = 'purchase_receipt';
    case ProductionIssue = 'production_issue';
    case ProductionReceipt = 'production_receipt';
    /** Ingredients taken out when a recipe item (a dish, a drink) is sold. */
    case SaleRecipe = 'sale_recipe';
    case SaleRecipeVoid = 'sale_recipe_void';
}
