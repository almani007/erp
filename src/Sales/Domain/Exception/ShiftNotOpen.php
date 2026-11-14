<?php

declare(strict_types=1);

namespace Sales\Domain\Exception;

final class ShiftNotOpen extends \DomainException
{
    public static function forCashier(): self
    {
        return new self('You have no open shift. Open a shift before recording sales.');
    }

    public static function alreadyClosed(string $shiftNo): self
    {
        return new self("Shift {$shiftNo} is already closed.");
    }
}
