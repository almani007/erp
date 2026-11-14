<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Shared\Infrastructure\Concern\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProductBrand extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'product_brands';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'tenant_id', 'name', 'slug', 'logo_url', 'status'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }
}
