<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Shared\Infrastructure\Concern\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProductAttribute extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'product_attributes';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'tenant_id', 'name', 'type', 'options', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'sort_order' => 'integer'];
    }
}
