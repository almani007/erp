<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class GoodsReceiptItem extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'goods_receipt_items';

    protected $fillable = ['goods_receipt_id', 'purchase_order_item_id', 'product_id', 'quantity', 'unit_cost', 'tax_amount', 'line_total', 'batch_no', 'expiry_date'];

    public $timestamps = false;
}
