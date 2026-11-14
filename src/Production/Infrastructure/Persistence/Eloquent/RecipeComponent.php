<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Catalog\Infrastructure\Persistence\Eloquent\Unit;

final class RecipeComponent extends Model
{
    use HasUuids;

    protected $table = 'recipe_components';

    protected $fillable = [
        'recipe_version_id',
        'product_id',
        'qty',
        'unit_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:6',
            'is_primary' => 'boolean',
        ];
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
