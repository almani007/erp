<?php

declare(strict_types=1);

namespace Production\Domain\Event;

use Shared\Domain\Event\DomainEvent;

final readonly class WorkOrderReleased implements DomainEvent
{
    public function __construct(
        public string $productionOrderId,
        public string $tenantId,
        public string $companyId,
        public string $recipeVersionId,
        public string $plannedQty,
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
        return 'production.work_order.released';
    }
}
