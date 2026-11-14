<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class PurchaseOrder extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'supplier_id', 'po_no', 'status', 'order_date', 'expected_date',
        'notes', 'subtotal', 'tax_total', 'grand_total', 'created_by', 'approved_by', 'approved_at',
    ];

    public const string DRAFT = 'draft';

    public const string APPROVED = 'approved';

    public const string PARTIALLY_RECEIVED = 'partially_received';

    public const string RECEIVED = 'received';

    public const string CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return ['order_date' => 'date:Y-m-d', 'expected_date' => 'date:Y-m-d', 'approved_at' => 'datetime'];
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id');
    }
}
