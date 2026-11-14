<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class PurchaseReturn extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'purchase_returns';

    protected $fillable = ['company_id', 'branch_id', 'warehouse_id', 'supplier_id', 'goods_receipt_id', 'return_no', 'reason', 'subtotal', 'tax_total', 'grand_total', 'stock_cost', 'journal_entry_id', 'created_by', 'returned_at'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<PurchaseReturnItem, $this> */
    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Supplier, $this> */
    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
