<?php

declare(strict_types=1);

namespace Catalog\Domain\Event;

use DateTimeImmutable;
use Shared\Domain\Event\DomainEvent;

final readonly class PriceChanged implements DomainEvent
{
    public function __construct(
        public string $productId,
        public string $priceListId,
        public string $oldPrice,
        public string $newPrice,
        public string $currency,
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
        return 'catalog.price.changed';
    }
}
