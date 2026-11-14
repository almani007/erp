<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Production\Domain\Enum\QualityStatus;

final class QualityInspection extends Model
{
    use HasUuids;

    protected $table = 'quality_inspections';
    public $timestamps = false;

    protected $fillable = [
        'production_order_id',
        'product_id',
        'batch_id',
        'status',
        'inspected_by',
        'notes',
        'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => QualityStatus::class,
            'inspected_at' => 'datetime',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }
}
