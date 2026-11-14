<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

final class PurchaseReturnItem extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'purchase_return_items';

    protected $fillable = ['purchase_return_id', 'goods_receipt_item_id', 'product_id', 'quantity', 'unit_cost', 'tax_amount', 'line_total'];
}
