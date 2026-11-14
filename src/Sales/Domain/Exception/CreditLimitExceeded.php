<?php

declare(strict_types=1);

namespace Sales\Domain\Exception;

final class CreditLimitExceeded extends \DomainException
{
    public static function forCustomer(string $name, string $limit, string $outstanding, string $requested): self
    {
        return new self("Credit limit exceeded for {$name}: limit {$limit}, outstanding {$outstanding}, this sale {$requested}.");
    }
}
