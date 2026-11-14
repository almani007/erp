<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Currency extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'currencies';

    protected $fillable = ['code', 'name', 'symbol', 'status'];
}
