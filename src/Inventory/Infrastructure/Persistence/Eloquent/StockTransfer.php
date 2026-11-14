<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class StockTransfer extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public const string IN_TRANSIT = 'in_transit';

    public const string RECEIVED = 'received';

    protected $table = 'stock_transfers';

    protected $fillable = [
        'company_id', 'transfer_no', 'source_warehouse_id', 'destination_warehouse_id', 'status', 'notes',
        'dispatched_by', 'dispatched_at', 'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }

    /** @return HasMany<StockTransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'stock_transfer_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }
}
