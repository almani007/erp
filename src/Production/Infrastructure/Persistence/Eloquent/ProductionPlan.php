<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProductionPlan extends Model
{
    use HasUuids;

    protected $table = 'production_plans';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'plan_no',
        'name',
        'planned_date',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }
}
