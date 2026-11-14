<?php

declare(strict_types=1);

namespace Rentals\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class RentalContract extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rental_contracts';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'contract_no', 'customer_id', 'rent_product_id', 'item_product_id', 'item_description',
        'serial_no', 'quantity', 'start_date', 'end_date', 'billing_frequency', 'rent_amount', 'next_bill_date', 'payment_days',
        'deposit_amount', 'deposit_method', 'deposit_refunded', 'deposit_deducted', 'status', 'returned_on', 'return_notes', 'notes',
        'invoices_count', 'last_error', 'created_by',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'next_bill_date' => 'date:Y-m-d', 'returned_on' => 'date:Y-m-d'];
    }
}
