<?php

declare(strict_types=1);

namespace Sales\Domain\Exception;

final class TerminalOccupied extends \DomainException
{
    public static function create(): self
    {
        return new self('This terminal already has an open shift, or you already have a shift open on another terminal.');
    }
}
