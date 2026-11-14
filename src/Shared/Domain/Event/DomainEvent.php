<?php

declare(strict_types=1);

namespace Shared\Domain\Event;

interface DomainEvent
{
    public function occurredAt(): \DateTimeImmutable;

    public function aggregateId(): string;

    public function eventType(): string;
}
