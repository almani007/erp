<?php

declare(strict_types=1);

namespace Catalog\Domain\Enum;

enum ProductStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Discontinued = 'discontinued';

    public function isSellable(): bool
    {
        return $this === self::Active;
    }

    public function isPurchasable(): bool
    {
        return $this !== self::Discontinued;
    }
}
