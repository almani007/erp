<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class ProductUnit extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'product_units';

    protected $fillable = ['product_id', 'name', 'factor', 'price', 'wholesale_price', 'barcode'];
}
