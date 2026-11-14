<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class SalesReturn extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'sales_returns';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'sale_id', 'shift_id', 'return_no', 'reason', 'refund_method',
        'net_total', 'tax_total', 'grand_total', 'cost_total', 'journal_entry_id', 'created_by', 'returned_at',
    ];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime'];
    }

    /** @return HasMany<SalesReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'sales_return_id');
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
