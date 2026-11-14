<?php

declare(strict_types=1);

namespace Purchasing\Domain\Exception;

final class InvoiceMismatch extends \DomainException
{
    public static function quantityVariance(string $invoiceId, string $productId): self
    {
        return new self(
            "Invoice {$invoiceId} quantity does not match GRN for product {$productId}."
        );
    }

    public static function priceVariance(string $invoiceId, string $productId, string $expected, string $actual): self
    {
        return new self(
            "Invoice {$invoiceId} price variance for product {$productId}: expected {$expected}, got {$actual}."
        );
    }
}
