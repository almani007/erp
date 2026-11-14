<?php

declare(strict_types=1);

namespace Integration\Infrastructure\Outbox;

use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\EventOutbox;
use Shared\Domain\Contract\TenantContext;
use Symfony\Component\Uid\Uuid;

final class DatabaseEventOutbox implements EventOutbox
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function record(string $eventType, string $aggregateType, string $aggregateId, array $payload): void
    {
        DB::table('outbox_events')->insert([
            'id' => Uuid::v7()->toRfc4122(),
            'tenant_id' => $this->tenant->requireId(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
