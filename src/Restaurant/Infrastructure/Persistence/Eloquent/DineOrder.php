<?php

declare(strict_types=1);

namespace Restaurant\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class DineOrder extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'dine_orders';

    protected $fillable = ['branch_id', 'table_id', 'order_no', 'order_type', 'guests', 'waiter_id', 'customer_id', 'customer_name', 'customer_phone', 'delivery_address', 'status', 'service_charge_percent', 'notes', 'sale_id', 'opened_by', 'opened_at', 'closed_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<DineOrderLine, $this> */
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DineOrderLine::class)->orderBy('created_at');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<DiningTable, $this> */
    public function table(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }
}
