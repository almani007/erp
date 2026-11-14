<?php

declare(strict_types=1);

namespace Identity\Domain\Exception;

final class AuthenticationFailed extends \DomainException
{
    public static function invalidCredentials(): self
    {
        return new self('The provided credentials are incorrect.');
    }
}
