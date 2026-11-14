<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

final class CustomerPaymentAllocation extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'customer_payment_allocations';

    protected $fillable = ['customer_payment_id', 'sale_id', 'amount'];
}
