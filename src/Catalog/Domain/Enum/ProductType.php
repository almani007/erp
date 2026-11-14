<?php

declare(strict_types=1);

namespace Catalog\Domain\Enum;

enum ProductType: string
{
    case Goods = 'goods';
    case Service = 'service';
    case Composite = 'composite';
    case NonStock = 'non_stock';

    public function tracksInventory(): bool
    {
        return match ($this) {
            self::Goods, self::Composite => true,
            self::Service, self::NonStock => false,
        };
    }
}
