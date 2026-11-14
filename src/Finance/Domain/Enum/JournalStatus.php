<?php

declare(strict_types=1);

namespace Finance\Domain\Enum;

enum JournalStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function canPost(): bool
    {
        return $this === self::Draft;
    }

    public function canReverse(): bool
    {
        return $this === self::Posted;
    }

    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
