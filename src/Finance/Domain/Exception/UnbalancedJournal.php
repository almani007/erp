<?php

declare(strict_types=1);

namespace Finance\Domain\Exception;

use DomainException;

final class UnbalancedJournal extends DomainException
{
    public static function create(string $totalDebits, string $totalCredits): self
    {
        return new self(
            "Journal entry is unbalanced: total debits ({$totalDebits}) do not equal total credits ({$totalCredits})."
        );
    }
}
