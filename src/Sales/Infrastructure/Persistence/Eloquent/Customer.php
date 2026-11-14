<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Customer extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'customers';

    protected $fillable = ['customer_code', 'name', 'phone', 'email', 'tax_no', 'credit_limit', 'status', 'price_tier'];

    protected function casts(): array
    {
        return ['loyalty_points' => 'integer'];
    }
}
