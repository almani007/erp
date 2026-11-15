<?php

declare(strict_types=1);

namespace Sales\Domain;

/**
 * Where a sale is recorded from. A POS sale comes from a cashier's open
 * shift at a terminal; a back-office invoice has no shift or terminal, a
 * due date, and usually comes from a sales order.
 */
final readonly class SaleOrigin
{
    public const string POS = 'pos';

    public const string INVOICE = 'invoice';

    public function __construct(
        public string $companyId,
        public string $branchId,
        public string $warehouseId,
        public string $userId,
        public string $channel = self::POS,
        public ?string $terminalId = null,
        public ?string $shiftId = null,
        public ?string $dueDate = null,
        public ?string $salesOrderId = null,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?string $soldAt = null, // when the till was offline: the time the sale really happened
    ) {}

    public function isPos(): bool
    {
        return $this->channel === self::POS;
    }
}
