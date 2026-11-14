<?php

declare(strict_types=1);

namespace Purchasing\Domain\Enum;

enum RequisitionStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Ordered = 'ordered';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::PendingApproval,
            self::PendingApproval => in_array($next, [self::Approved, self::Rejected]),
            self::Approved => $next === self::Ordered,
            self::Rejected, self::Ordered => false,
        };
    }
}
