<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Setting extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'settings';

    protected $fillable = ['scope_type', 'scope_id', 'group', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
