<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

final class SupplierBillLine extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'supplier_bill_lines';

    protected $fillable = ['supplier_bill_id', 'account_id', 'description', 'amount', 'tax_amount'];
}
