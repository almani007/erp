<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class StockCount extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public const string IN_PROGRESS = 'in_progress';

    public const string POSTED = 'posted';

    public const string CANCELLED = 'cancelled';

    protected $table = 'stock_counts';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'count_no', 'status', 'notes', 'stock_adjustment_id',
        'started_by', 'started_at', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'stock_count_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
