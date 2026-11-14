<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class ExchangeRate extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'exchange_rates';

    protected $fillable = ['company_id', 'currency', 'rate_date', 'rate', 'source', 'created_by'];

    protected function casts(): array
    {
        return ['rate_date' => 'date:Y-m-d'];
    }
}
