<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Production\Domain\Enum\WorkOrderStatus;

final class ProductionOrder extends Model
{
    use HasUuids;

    protected $table = 'production_orders';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'branch_id',
        'production_plan_id',
        'order_no',
        'recipe_version_id',
        'planned_qty',
        'actual_qty',
        'status',
        'planned_start',
        'actual_start',
        'actual_end',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'planned_qty' => 'decimal:6',
            'actual_qty' => 'decimal:6',
            'planned_start' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
        ];
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function materialIssues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ProductionOutput::class);
    }

    public function wastage(): HasMany
    {
        return $this->hasMany(ProductionWastage::class);
    }

    public function qualityInspections(): HasMany
    {
        return $this->hasMany(QualityInspection::class);
    }
}
