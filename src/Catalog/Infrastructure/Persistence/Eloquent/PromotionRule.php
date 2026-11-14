<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Shared\Infrastructure\Concern\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PromotionRule extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'promotion_rules';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'tenant_id', 'company_id', 'name', 'type', 'value', 'conditions', 'start_at', 'end_at', 'status', 'priority', 'branch_id', 'requires_coupon'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'priority' => 'integer',
            'requires_coupon' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class, 'promotion_rule_id');
    }
}
