<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Shared\Infrastructure\Concern\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PriceList extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'price_lists';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'tenant_id', 'company_id', 'code', 'name', 'currency', 'effective_from', 'effective_to', 'status'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'price_list_id');
    }
}
