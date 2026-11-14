<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class PurchaseOrderItem extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'purchase_order_items';

    protected $fillable = ['purchase_order_id', 'product_id', 'quantity', 'unit_cost', 'tax_rate', 'received_quantity'];

    public $timestamps = false;

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
