<?php

declare(strict_types=1);

namespace Production\Domain\Enum;

enum QualityResult: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Hold = 'hold';
    case Rework = 'rework';

    public function allowsRelease(): bool
    {
        return $this === self::Passed;
    }
}
