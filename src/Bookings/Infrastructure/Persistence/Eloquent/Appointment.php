<?php

declare(strict_types=1);

namespace Bookings\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Appointment extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'appointments';

    protected $fillable = ['branch_id', 'resource_id', 'product_id', 'appointment_no', 'customer_id', 'customer_name', 'customer_phone', 'starts_at', 'ends_at', 'status', 'price', 'notes', 'sale_id', 'reminded_at', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
}
