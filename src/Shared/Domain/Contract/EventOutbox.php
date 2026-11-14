<?php

declare(strict_types=1);

namespace Shared\Domain\Contract;

interface EventOutbox
{
    /**
     * Record an integration event in the caller's database transaction, so
     * the event exists if and only if the business change committed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(string $eventType, string $aggregateType, string $aggregateId, array $payload): void;
}
