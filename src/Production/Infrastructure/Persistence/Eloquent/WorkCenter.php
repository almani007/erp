<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WorkCenter extends Model
{
    use HasUuids;

    protected $table = 'work_centers';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'branch_id',
        'code',
        'name',
        'capacity_per_hour',
        'cost_per_hour',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'capacity_per_hour' => 'decimal:4',
            'cost_per_hour' => 'decimal:4',
        ];
    }
}
