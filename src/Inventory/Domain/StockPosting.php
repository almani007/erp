<?php

declare(strict_types=1);

namespace Inventory\Domain;

use Inventory\Domain\Enum\MovementType;

/** One requested change to the stock ledger. Quantity is always positive. */
final readonly class StockPosting
{
    /**
     * Batch details only matter for products that track batch or expiry:
     *
     * @param  string|null  $batchNo  incoming stock: the batch it belongs to
     * @param  string|null  $expiryDate  incoming stock: Y-m-d expiry of that batch
     * @param  string|null  $lotsFromSourceType  incoming stock that is coming *back* (void, return,
     *                                           transfer receipt): the document that originally took it out,
     *                                           so it returns to the same batches
     */
    public function __construct(
        public string $companyId,
        public string $warehouseId,
        public string $productId,
        public string $quantity,
        public MovementType $type,
        public string $sourceType,
        public string $sourceId,
        public ?string $note = null,
        public ?string $batchNo = null,
        public ?string $expiryDate = null,
        public ?string $lotsFromSourceType = null,
        public ?string $lotsFromSourceId = null,
    ) {}
}
