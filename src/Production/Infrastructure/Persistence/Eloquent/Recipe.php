<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Recipe extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'recipes';

    protected $fillable = ['product_id', 'yield_quantity', 'consume_on_sale', 'notes', 'status'];

    protected function casts(): array
    {
        return ['consume_on_sale' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<RecipeOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(RecipeOperation::class)->orderBy('sequence');
    }

    /** @return HasMany<RecipeItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }
}
