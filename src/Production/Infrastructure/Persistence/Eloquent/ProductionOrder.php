<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class ProductionOrder extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public const string PLANNED = 'planned';

    public const string COMPLETED = 'completed';

    public const string CANCELLED = 'cancelled';

    protected $table = 'production_orders';

    protected $fillable = [
        'company_id', 'warehouse_id', 'recipe_id', 'product_id', 'order_no', 'planned_quantity', 'produced_quantity', 'status',
        'material_cost', 'extra_cost', 'unit_cost', 'batch_no', 'expiry_date', 'notes', 'journal_entry_id',
        'created_by', 'completed_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'expiry_date' => 'date:Y-m-d'];
    }

    /** @return HasMany<ProductionOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
