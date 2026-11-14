<?php

declare(strict_types=1);

namespace Finance\Domain\Enum;

enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Locked = 'locked';

    public function allowsPosting(): bool
    {
        return $this === self::Open;
    }
}
