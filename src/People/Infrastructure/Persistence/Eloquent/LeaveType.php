<?php

declare(strict_types=1);

namespace People\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class LeaveType extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'leave_types';

    protected $fillable = ['code', 'name', 'paid', 'days_per_year', 'use_employee_days', 'carry_forward_max', 'allow_half_day', 'status'];

    protected function casts(): array
    {
        return ['paid' => 'boolean', 'use_employee_days' => 'boolean', 'allow_half_day' => 'boolean'];
    }
}
