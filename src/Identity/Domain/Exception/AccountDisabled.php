<?php

declare(strict_types=1);

namespace Identity\Domain\Exception;

final class AccountDisabled extends \DomainException
{
    public static function forUser(string $userId): self
    {
        return new self('This account is disabled. Contact your administrator.');
    }
}
