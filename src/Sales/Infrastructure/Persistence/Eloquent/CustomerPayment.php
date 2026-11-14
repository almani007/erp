<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class CustomerPayment extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'customer_payments';

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'receipt_no', 'method', 'amount', 'reference',
        'journal_entry_id', 'created_by', 'received_at',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
