<?php

declare(strict_types=1);

namespace Assets\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class FixedAsset extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'fixed_assets';

    protected $fillable = ['company_id', 'branch_id', 'category_id', 'asset_no', 'name', 'serial_no', 'location', 'custodian_id', 'supplier_id', 'purchase_date', 'in_service_date', 'cost', 'residual_value', 'method', 'useful_life_months', 'rate_percent', 'opening_depreciation', 'accumulated_depreciation', 'depreciated_to', 'funding', 'status', 'disposal_date', 'disposal_proceeds', 'disposal_gain_loss', 'notes', 'custom', 'acquisition_journal_id', 'disposal_journal_id', 'created_by'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date:Y-m-d', 'in_service_date' => 'date:Y-m-d', 'depreciated_to' => 'date:Y-m-d', 'disposal_date' => 'date:Y-m-d', 'custom' => 'array'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<AssetCategory, $this> */
    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetCategory::class);
    }
}
