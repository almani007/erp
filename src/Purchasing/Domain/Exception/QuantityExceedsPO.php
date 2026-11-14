<?php

declare(strict_types=1);

namespace Purchasing\Domain\Exception;

final class QuantityExceedsPO extends \DomainException
{
    public static function forItem(string $productId, string $received, string $ordered): self
    {
        return new self(
            "Received quantity ({$received}) exceeds PO quantity ({$ordered}) for product {$productId}."
        );
    }
}
