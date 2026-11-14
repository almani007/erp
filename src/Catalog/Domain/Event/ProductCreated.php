<?php

declare(strict_types=1);

namespace Catalog\Domain\Event;

use DateTimeImmutable;
use Shared\Domain\Event\DomainEvent;

final readonly class ProductCreated implements DomainEvent
{
    public function __construct(
        public string $productId,
        public string $tenantId,
        public string $sku,
        public string $name,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function aggregateId(): string
    {
        return $this->productId;
    }

    public function eventType(): string
    {
        return 'catalog.product.created';
    }
}
