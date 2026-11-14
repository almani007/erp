<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class StockAdjustment extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'stock_adjustments';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'adjustment_no', 'reason_code', 'notes',
        'total_value', 'journal_entry_id', 'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    /** @return HasMany<StockAdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'stock_adjustment_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
