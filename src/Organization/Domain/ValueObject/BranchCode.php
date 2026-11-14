<?php

declare(strict_types=1);

namespace Organization\Domain\ValueObject;

final readonly class BranchCode
{
    public function __construct(public string $value)
    {
        if (strlen($value) < 1 || strlen($value) > 30) {
            throw new \InvalidArgumentException('Branch code must be between 1 and 30 characters.');
        }

        if (! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new \InvalidArgumentException('Branch code may only contain alphanumeric characters, hyphens and underscores.');
        }
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
