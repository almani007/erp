<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Persistence\Eloquent;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class StockTransferLine extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public $timestamps = false;

    protected $table = 'stock_transfer_lines';

    protected $fillable = ['stock_transfer_id', 'product_id', 'quantity', 'unit_cost'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
