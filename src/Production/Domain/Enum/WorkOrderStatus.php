<?php

declare(strict_types=1);

namespace Production\Domain\Enum;

enum WorkOrderStatus: string
{
    case Planned = 'planned';
    case Released = 'released';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Planned => in_array($target, [self::Released, self::Cancelled]),
            self::Released => in_array($target, [self::InProgress, self::Cancelled]),
            self::InProgress => in_array($target, [self::Completed, self::Cancelled]),
            self::Completed => $target === self::Closed,
            self::Closed, self::Cancelled => false,
        };
    }
}
