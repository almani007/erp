<?php

declare(strict_types=1);

namespace People\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Attendance extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'attendance';

    protected $fillable = ['employee_id', 'date', 'status', 'overtime_hours', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
