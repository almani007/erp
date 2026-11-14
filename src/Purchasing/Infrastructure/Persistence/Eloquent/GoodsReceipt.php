<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class GoodsReceipt extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'goods_receipts';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'supplier_id', 'purchase_order_id', 'grn_no', 'supplier_invoice_no',
        'subtotal', 'tax_total', 'grand_total', 'journal_entry_id', 'received_by', 'received_at', 'supplier_bill_id', 'returned_total',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    /** @return HasMany<GoodsReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }
}
