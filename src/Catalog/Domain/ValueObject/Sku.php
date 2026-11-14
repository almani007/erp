<?php

declare(strict_types=1);

namespace Catalog\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Sku
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtoupper(trim($value));

        if ($normalized === '' || mb_strlen($normalized) > 80) {
            throw new InvalidArgumentException('SKU must be between 1 and 80 characters.');
        }

        $this->value = $normalized;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
