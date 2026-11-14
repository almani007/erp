<?php

declare(strict_types=1);

namespace Production\Domain\Exception;

final class InsufficientMaterial extends \DomainException
{
    public static function forProduct(string $productId, string $required, string $available): self
    {
        return new self(
            "Insufficient material for product [{$productId}]: required {$required}, available {$available}."
        );
    }
}
