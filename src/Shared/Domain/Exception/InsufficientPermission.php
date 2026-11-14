<?php

declare(strict_types=1);

namespace Shared\Domain\Exception;

final class InsufficientPermission extends \DomainException
{
    public static function for(string $permission): self
    {
        return new self("You do not have the required permission: {$permission}");
    }
}
