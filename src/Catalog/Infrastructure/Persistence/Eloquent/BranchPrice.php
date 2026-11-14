<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class BranchPrice extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'branch_prices';

    protected $fillable = ['branch_id', 'product_id', 'price'];
}
