<?php

declare(strict_types=1);

namespace Shared\Domain\Exception;

use Shared\Domain\ValueObject\Currency;

final class CurrencyMismatch extends \DomainException
{
    public static function between(Currency $a, Currency $b): self
    {
        return new self("Cannot operate on {$a->value} and {$b->value} — currency mismatch.");
    }
}
