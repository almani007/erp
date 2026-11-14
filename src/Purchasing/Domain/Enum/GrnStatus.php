<?php

declare(strict_types=1);

namespace Purchasing\Domain\Enum;

enum GrnStatus: string
{
    case Draft = 'draft';
    case Received = 'received';
    case Posted = 'posted';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Received,
            self::Received => $next === self::Posted,
            self::Posted => false,
        };
    }
}
