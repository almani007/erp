<?php

declare(strict_types=1);

namespace Inventory\Domain;

use Inventory\Domain\Enum\MovementType;

/** One requested change to the stock ledger. Quantity is always positive. */
final readonly class StockPosting
{
    public function __construct(
        public string $companyId,
        public string $warehouseId,
        public string $productId,
        public string $quantity,
        public MovementType $type,
        public string $sourceType,
        public string $sourceId,
        public ?string $note = null,
    ) {}
}
