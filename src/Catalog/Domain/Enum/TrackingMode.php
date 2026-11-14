<?php

declare(strict_types=1);

namespace Catalog\Domain\Enum;

enum TrackingMode: string
{
    case None = 'none';
    case Batch = 'batch';
    case Serial = 'serial';
    case Expiry = 'expiry';

    public function requiresLot(): bool
    {
        return match ($this) {
            self::Batch, self::Expiry => true,
            self::None, self::Serial => false,
        };
    }

    public function requiresSerial(): bool
    {
        return $this === self::Serial;
    }
}
