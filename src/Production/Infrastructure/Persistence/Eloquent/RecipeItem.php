<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Infrastructure\Concern\HasUuid;

/** Reached only through its tenant-scoped recipe. */
final class RecipeItem extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'recipe_items';

    protected $fillable = ['recipe_id', 'product_id', 'quantity', 'wastage_percent'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
