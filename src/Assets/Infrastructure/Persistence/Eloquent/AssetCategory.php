<?php

declare(strict_types=1);

namespace Assets\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class AssetCategory extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'asset_categories';

    protected $fillable = ['name', 'method', 'useful_life_months', 'rate_percent', 'residual_percent', 'status'];
}
