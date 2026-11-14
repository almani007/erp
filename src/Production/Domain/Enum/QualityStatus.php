<?php

declare(strict_types=1);

namespace Production\Domain\Enum;

enum QualityStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    case OnHold = 'on_hold';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Passed, self::Failed => true,
            default => false,
        };
    }
}
