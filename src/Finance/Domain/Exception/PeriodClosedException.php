<?php

declare(strict_types=1);

namespace Finance\Domain\Exception;

use DomainException;

final class PeriodClosedException extends DomainException
{
    public static function forDate(string $date, string $periodName): self
    {
        return new self(
            "Cannot post to date {$date}: accounting period '{$periodName}' is closed or locked."
        );
    }
}
