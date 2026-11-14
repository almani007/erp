<?php

declare(strict_types=1);

namespace Production\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

/** Reached only through its tenant-scoped recipe. */
final class RecipeOperation extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'recipe_operations';

    protected $fillable = ['recipe_id', 'sequence', 'work_centre_id', 'name', 'minutes'];
}
