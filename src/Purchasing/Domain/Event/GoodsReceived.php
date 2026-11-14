<?php

declare(strict_types=1);

namespace Purchasing\Domain\Event;

use Shared\Domain\Event\DomainEvent;

final readonly class GoodsReceived implements DomainEvent
{
    public function __construct(
        public string $goodsReceiptId,
        public string $tenantId,
        public string $companyId,
        public string $warehouseId,
        public string $purchaseOrderId,
        public string $supplierId,
        public \DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function aggregateId(): string
    {
        return $this->goodsReceiptId;
    }

    public function eventType(): string
    {
        return 'purchasing.goods.received';
    }
}
