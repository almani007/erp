<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Sale extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public const string COMPLETED = 'completed';

    public const string VOIDED = 'voided';

    protected $table = 'sales';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'terminal_id', 'shift_id', 'cashier_id', 'customer_id',
        'sale_no', 'status', 'currency', 'subtotal', 'discount_total', 'tax_total', 'grand_total',
        'paid_total', 'change_total', 'cost_total', 'journal_entry_id', 'idempotency_key', 'sold_at',
        'voided_at', 'voided_by', 'void_reason', 'coupon_code',
    ];

    protected function casts(): array
    {
        return ['sold_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    /** @return HasMany<SaleTender, $this> */
    public function tenders(): HasMany
    {
        return $this->hasMany(SaleTender::class, 'sale_id');
    }

    /** @return HasMany<SalesReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'sale_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
