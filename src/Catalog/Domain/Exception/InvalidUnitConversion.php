<?php

declare(strict_types=1);

namespace Catalog\Domain\Exception;

use DomainException;

final class InvalidUnitConversion extends DomainException
{
    public static function notFound(string $fromUnit, string $toUnit): self
    {
        return new self("No conversion factor defined from unit '{$fromUnit}' to unit '{$toUnit}'.");
    }

    public static function zeroFactor(string $fromUnit, string $toUnit): self
    {
        return new self("Conversion factor from '{$fromUnit}' to '{$toUnit}' cannot be zero.");
    }
}
