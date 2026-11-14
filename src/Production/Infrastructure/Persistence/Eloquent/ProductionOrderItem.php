<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Infrastructure\Concern\HasUuid;

final class ProductionOrderItem extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'production_order_items';

    protected $fillable = ['production_order_id', 'product_id', 'required_quantity', 'issued_quantity', 'unit_cost'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
