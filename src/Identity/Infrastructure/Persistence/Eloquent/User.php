<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Shared\Domain\Contract\TenantContext;
use Shared\Infrastructure\Concern\HasUuid;
use Spatie\Permission\Traits\HasRoles;

/**
 * Deliberately NOT globally tenant-scoped: the tenant context is derived
 * from the authenticated user, so resolving the user must not depend on it.
 * Tenant-facing queries go through {@see User::scopeInCurrentTenant()}.
 */
final class User extends Authenticatable
{
    use HasApiTokens;
    use HasRoles;
    use HasUuid;

    protected $table = 'users';

    /** Guard name spatie/laravel-permission stores roles and permissions under. */
    protected $guard_name = 'web';

    protected $fillable = ['name', 'email', 'password', 'status', 'all_branches'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'all_branches' => 'boolean',
            'mfa_enabled' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeInCurrentTenant(Builder $query): void
    {
        $query->where('tenant_id', app(TenantContext::class)->requireId());
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user', 'user_id', 'branch_id');
    }

    public function hasAccessToBranch(string $branchId): bool
    {
        if ($this->all_branches) {
            return Branch::query()->whereKey($branchId)->exists();
        }

        return $this->branches()->whereKey($branchId)->exists();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
