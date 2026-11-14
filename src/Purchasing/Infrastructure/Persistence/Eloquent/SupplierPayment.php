<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class SupplierPayment extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'supplier_payments';

    protected $fillable = ['company_id', 'supplier_id', 'payment_no', 'method', 'amount', 'reference', 'journal_entry_id', 'created_by', 'paid_at', 'currency', 'fx_rate', 'foreign_amount', 'fx_difference'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }
}
