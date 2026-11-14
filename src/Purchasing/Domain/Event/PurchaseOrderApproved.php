<?php

declare(strict_types=1);

namespace Purchasing\Domain\Event;

use Shared\Domain\Event\DomainEvent;

final readonly class PurchaseOrderApproved implements DomainEvent
{
    public function __construct(
        public string $purchaseOrderId,
        public string $tenantId,
        public string $companyId,
        public string $supplierId,
        public string $approvedBy,
        public \DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function aggregateId(): string
    {
        return $this->purchaseOrderId;
    }

    public function eventType(): string
    {
        return 'purchasing.purchase_order.approved';
    }
}
