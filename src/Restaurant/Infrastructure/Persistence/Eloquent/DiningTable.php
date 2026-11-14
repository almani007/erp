<?php

declare(strict_types=1);

namespace Restaurant\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class DiningTable extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'dining_tables';

    protected $fillable = ['branch_id', 'area', 'name', 'seats', 'sort_order', 'status'];
}
