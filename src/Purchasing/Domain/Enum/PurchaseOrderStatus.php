<?php

declare(strict_types=1);

namespace Purchasing\Domain\Enum;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::PendingApproval, self::Cancelled]),
            self::PendingApproval => in_array($next, [self::Approved, self::Cancelled]),
            self::Approved => in_array($next, [self::Sent, self::Cancelled]),
            self::Sent => in_array($next, [self::PartiallyReceived, self::Received, self::Cancelled]),
            self::PartiallyReceived => in_array($next, [self::Received, self::Cancelled]),
            self::Received, self::Cancelled => false,
        };
    }
}
