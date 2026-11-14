<?php

declare(strict_types=1);

namespace Assets\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class DepreciationRun extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'depreciation_runs';

    protected $fillable = ['company_id', 'run_no', 'period', 'total', 'asset_count', 'status', 'journal_entry_id', 'created_by'];

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<DepreciationLine, $this> */
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DepreciationLine::class);
    }
}
