<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Shared\Infrastructure\Concern\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Unit extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'units';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'tenant_id', 'name', 'abbreviation', 'is_base'];

    protected function casts(): array
    {
        return ['is_base' => 'boolean'];
    }
}
