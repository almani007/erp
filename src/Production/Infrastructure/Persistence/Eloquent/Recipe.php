<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Catalog\Infrastructure\Persistence\Eloquent\Product;

final class Recipe extends Model
{
    use HasUuids;

    protected $table = 'recipes';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'product_id',
        'code',
        'name',
        'status',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }

    public function activeVersion(): ?RecipeVersion
    {
        return $this->versions()
            ->where('status', 'active')
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->orderByDesc('version_number')
            ->first();
    }
}
