<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class DeliveryNote extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'delivery_notes';

    protected $fillable = [
        'company_id', 'branch_id', 'sale_id', 'customer_id', 'note_no', 'status', 'ship_to_name', 'ship_to_phone', 'ship_to_address',
        'scheduled_for', 'driver_employee_id', 'driver_name', 'vehicle_no', 'cod_amount', 'collected_amount', 'customer_payment_id',
        'received_by', 'signature', 'outcome_note', 'dispatched_at', 'delivered_at', 'created_by',
    ];

    protected $hidden = ['signature'];

    protected function casts(): array
    {
        return ['scheduled_for' => 'date:Y-m-d', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    /** @return HasMany<DeliveryNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class);
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
