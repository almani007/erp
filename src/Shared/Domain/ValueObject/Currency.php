<?php

declare(strict_types=1);

namespace Shared\Domain\ValueObject;

enum Currency: string
{
    case PKR = 'PKR';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case SAR = 'SAR';
    case AED = 'AED';

    public function symbol(): string
    {
        return match ($this) {
            self::PKR => 'Rs',
            self::USD => '$',
            self::EUR => '€',
            self::GBP => '£',
            self::SAR => 'SAR',
            self::AED => 'AED',
        };
    }

    public function decimalPlaces(): int
    {
        return match ($this) {
            default => 2,
        };
    }
}
