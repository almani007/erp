<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Catalog\Infrastructure\Persistence\Eloquent\Product;

final class ProductionOutput extends Model
{
    use HasUuids;

    protected $table = 'production_outputs';
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'production_order_id',
        'product_id',
        'qty',
        'output_type',
        'unit_cost',
        'lot_id',
        'recorded_at',
        'recorded_by',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
