<?php

declare(strict_types=1);

namespace Inventory\Domain\Repository;

use Inventory\Domain\StockPosting;

/**
 * The only writer of stock quantities. Both methods must run inside the
 * caller's database transaction so stock, the source document and its
 * accounting commit together.
 */
interface StockLedger
{
    /**
     * Add stock at the given unit cost and re-average the cost.
     *
     * @return string the unit cost recorded
     */
    public function receive(StockPosting $posting, string $unitCost): string;

    /**
     * Remove stock at the current weighted-average cost.
     *
     * @return string the unit cost the stock left at
     *
     * @throws \Inventory\Domain\Exception\InsufficientStock when negative stock is not allowed
     */
    public function issue(StockPosting $posting): string;
}
