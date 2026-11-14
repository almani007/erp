<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class PosTerminal extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'pos_terminals';

    protected $fillable = ['branch_id', 'warehouse_id', 'code', 'name', 'status'];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
