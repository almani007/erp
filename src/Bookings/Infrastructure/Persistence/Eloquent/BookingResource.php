<?php

declare(strict_types=1);

namespace Bookings\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class BookingResource extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'booking_resources';

    protected $fillable = ['branch_id', 'name', 'kind', 'employee_id', 'color', 'working_hours', 'service_ids', 'status'];

    protected function casts(): array
    {
        return ['working_hours' => 'array', 'service_ids' => 'array'];
    }
}
