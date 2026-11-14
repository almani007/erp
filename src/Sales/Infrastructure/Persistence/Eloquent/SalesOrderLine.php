<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Infrastructure\Concern\HasUuid;

final class SalesOrderLine extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'sales_order_lines';

    protected $fillable = [
        'sales_order_id', 'line_no', 'product_id', 'description', 'quantity', 'unit_price', 'discount', 'tax_amount', 'line_total', 'invoiced_quantity',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
