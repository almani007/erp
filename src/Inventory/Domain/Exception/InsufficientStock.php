<?php

declare(strict_types=1);

namespace Inventory\Domain\Exception;

final class InsufficientStock extends \DomainException
{
    public static function for(string $productName, string $available, string $requested): self
    {
        return new self("Not enough stock for {$productName}: {$available} available, {$requested} requested.");
    }
}
