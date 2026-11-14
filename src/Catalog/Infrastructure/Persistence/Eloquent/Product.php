<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasCustomFields;
use Catalog\Domain\Enum\ProductStatus;
use Catalog\Domain\Enum\ProductType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Product extends Model
{
    use BelongsToTenant;
    use HasCustomFields;
    use HasUuids;

    protected $table = 'products';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'sku',
        'name',
        'product_type',
        'base_unit_id',
        'category_id',
        'brand_id',
        'tax_code_id',
        'track_batch',
        'track_serial',
        'track_expiry',
        'sale_price',
        'cost_price',
        'parent_id',
        'variant_attributes',
        'wholesale_price',
        'wholesale_min_qty',
        'pricing_mode',
        'rate_code',
        'weight',
        'making_charge',
        'wastage_percent',
        'purity',
        'warranty_months',
        'reorder_level',
        'status',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'status' => ProductStatus::class,
            'track_batch' => 'boolean',
            'track_serial' => 'boolean',
            'track_expiry' => 'boolean',
            'metadata' => 'array',
            'variant_attributes' => 'array',
            'custom' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'brand_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    /** Packs this product can also be sold in (carton, dozen …). */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class, 'product_id')->orderBy('factor');
    }

    /** Size/colour variants of this style. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sku');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'product_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'product_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class, 'product_id');
    }
}
