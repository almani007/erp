<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductionWastage extends Model
{
    use HasUuids;

    protected $table = 'production_wastage';
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'production_order_id',
        'product_id',
        'qty',
        'unit_id',
        'unit_cost',
        'reason',
        'lot_id',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'recorded_at' => 'datetime',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }
}
