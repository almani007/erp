<?php

declare(strict_types=1);

namespace People\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Holiday extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'holidays';

    protected $fillable = ['branch_id', 'date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
