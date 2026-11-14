<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class MarketRate extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'market_rates';

    protected $fillable = ['code', 'name', 'per_unit', 'rate'];
}
