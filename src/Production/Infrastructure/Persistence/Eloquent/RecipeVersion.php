<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RecipeVersion extends Model
{
    use HasUuids;

    protected $table = 'recipe_versions';

    protected $fillable = [
        'recipe_id',
        'version_number',
        'effective_from',
        'effective_to',
        'yield_qty',
        'yield_unit_id',
        'expected_wastage_pct',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'yield_qty' => 'decimal:6',
            'expected_wastage_pct' => 'decimal:4',
            'version_number' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(RecipeComponent::class);
    }
}
