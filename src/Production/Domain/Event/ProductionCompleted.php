<?php

declare(strict_types=1);

namespace Production\Domain\Event;

use Shared\Domain\Event\DomainEvent;

final readonly class ProductionCompleted implements DomainEvent
{
    public function __construct(
        public string $productionOrderId,
        public string $productId,
        public string $outputQty,
        public string $scrapQty,
        public string $wastageQty,
        public string $tenantId,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function aggregateId(): string
    {
        return $this->productionOrderId;
    }

    public function eventType(): string
    {
        return 'production.order.completed';
    }
}
