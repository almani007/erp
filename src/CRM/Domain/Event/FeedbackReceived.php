<?php

declare(strict_types=1);

namespace CRM\Domain\Event;

use Shared\Domain\Event\DomainEvent;

final readonly class FeedbackReceived implements DomainEvent
{
    public function __construct(
        public string $feedbackId,
        public string $tenantId,
        public ?string $customerId,
        public string $branchId,
        public string $sentiment,
        public \DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function aggregateId(): string
    {
        return $this->feedbackId;
    }

    public function eventType(): string
    {
        return 'crm.feedback.received';
    }
}
