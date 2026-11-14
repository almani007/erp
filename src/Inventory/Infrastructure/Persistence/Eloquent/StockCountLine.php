<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Persistence\Eloquent;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class StockCountLine extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public $timestamps = false;

    protected $table = 'stock_count_lines';

    protected $fillable = ['stock_count_id', 'product_id', 'system_qty', 'counted_qty', 'variance_qty'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
