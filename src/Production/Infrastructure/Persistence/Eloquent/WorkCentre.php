<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class WorkCentre extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'work_centres';

    protected $fillable = ['name', 'hourly_rate', 'status'];
}
