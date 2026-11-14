<?php

declare(strict_types=1);

namespace Restaurant\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class ModifierGroup extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'modifier_groups';

    protected $fillable = ['name', 'min_select', 'max_select', 'options', 'status'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
