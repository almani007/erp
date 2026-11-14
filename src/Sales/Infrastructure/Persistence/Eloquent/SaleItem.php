<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class SaleItem extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public $timestamps = false;

    protected $table = 'sale_items';

    protected $fillable = [
        'sale_id', 'product_id', 'sku', 'product_name', 'quantity', 'unit_price', 'discount_amount',
        'tax_rate', 'tax_amount', 'net_amount', 'line_total', 'cost_snapshot', 'promotion_name',
    ];
}
