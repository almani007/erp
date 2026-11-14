<?php

declare(strict_types=1);

namespace Shared\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;

final readonly class CompanyId
{
    public function __construct(public string $value)
    {
        if (! Uuid::isValid($value)) {
            throw new \InvalidArgumentException("Invalid CompanyId: {$value}");
        }
    }

    public static function generate(): self
    {
        return new self(Uuid::v7()->toRfc4122());
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
