<?php

declare(strict_types=1);

namespace Records\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Record extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'records';

    protected $fillable = ['record_type_id', 'record_no', 'branch_id', 'customer_id', 'status', 'data', 'created_by'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
