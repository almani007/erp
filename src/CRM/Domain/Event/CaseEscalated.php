<?php

declare(strict_types=1);

namespace CRM\Domain\Event;

use Shared\Domain\Event\DomainEvent;

final readonly class CaseEscalated implements DomainEvent
{
    public function __construct(
        public string $caseId,
        public string $tenantId,
        public string $customerId,
        public string $previousAssignee,
        public string $escalatedTo,
        public \DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function aggregateId(): string
    {
        return $this->caseId;
    }

    public function eventType(): string
    {
        return 'crm.case.escalated';
    }
}
