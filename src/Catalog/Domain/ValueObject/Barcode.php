<?php

declare(strict_types=1);

namespace Catalog\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Barcode
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        if ($normalized === '' || mb_strlen($normalized) > 128) {
            throw new InvalidArgumentException('Barcode must be between 1 and 128 characters.');
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
