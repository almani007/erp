<?php

declare(strict_types=1);

namespace Assets\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

final class DepreciationLine extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'depreciation_lines';

    protected $fillable = ['depreciation_run_id', 'fixed_asset_id', 'amount', 'accumulated_after', 'previous_depreciated_to'];

    protected function casts(): array
    {
        return ['previous_depreciated_to' => 'date:Y-m-d'];
    }
}
