<?php

declare(strict_types=1);

namespace Finance\Domain\Exception;

use DomainException;

final class ImmutableRecord extends DomainException
{
    public static function journalAlreadyPosted(string $journalId): self
    {
        return new self(
            "Journal entry {$journalId} is already posted and cannot be modified. Use reversal instead."
        );
    }

    public static function journalAlreadyReversed(string $journalId): self
    {
        return new self(
            "Journal entry {$journalId} has already been reversed."
        );
    }
}
