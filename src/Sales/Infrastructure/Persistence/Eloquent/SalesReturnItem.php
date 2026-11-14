<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class SalesReturnItem extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public $timestamps = false;

    protected $table = 'sales_return_items';

    protected $fillable = [
        'sales_return_id', 'sale_item_id', 'product_id', 'quantity', 'net_amount', 'tax_amount',
        'line_total', 'unit_cost', 'restocked',
    ];

    protected function casts(): array
    {
        return ['restocked' => 'boolean'];
    }
}
