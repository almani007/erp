<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Company extends Model
{
    use HasUuid;
    use BelongsToTenant;

    protected $table = 'companies';

    protected $fillable = [
        'tenant_id',
        'code',
        'legal_name',
        'base_currency',
        'tax_registration_no',
        'fiscal_year_start_month',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'company_id');
    }
}
