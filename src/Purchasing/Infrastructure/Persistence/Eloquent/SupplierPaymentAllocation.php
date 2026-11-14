<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

final class SupplierPaymentAllocation extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'supplier_payment_allocations';

    protected $fillable = ['supplier_payment_id', 'supplier_bill_id', 'amount'];
}
