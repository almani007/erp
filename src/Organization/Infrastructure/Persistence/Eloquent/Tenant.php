<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Infrastructure\Concern\HasUuid;

final class Tenant extends Model
{
    use HasUuid;

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'legal_name',
        'status',
        'default_timezone',
        'default_currency',
    ];

    /** @return HasMany<Company, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'tenant_id');
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'tenant_id');
    }
}
