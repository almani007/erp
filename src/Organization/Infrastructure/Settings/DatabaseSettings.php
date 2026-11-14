<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Settings;

use Organization\Infrastructure\Persistence\Eloquent\Setting;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;

final class DatabaseSettings implements Settings
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function get(string $group, string $key, ?string $companyId = null, ?string $branchId = null, mixed $default = null): mixed
    {
        $scopes = array_filter([
            $branchId !== null ? ['branch', $branchId] : null,
            $companyId !== null ? ['company', $companyId] : null,
            ['tenant', $this->tenant->requireId()],
        ]);

        foreach ($scopes as [$type, $id]) {
            $setting = Setting::query()
                ->where('scope_type', $type)
                ->where('scope_id', $id)
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            if ($setting !== null) {
                return $setting->value;
            }
        }

        return $default;
    }

    public function set(string $group, string $key, mixed $value, ?string $companyId = null, ?string $branchId = null): void
    {
        [$type, $id] = match (true) {
            $branchId !== null => ['branch', $branchId],
            $companyId !== null => ['company', $companyId],
            default => ['tenant', $this->tenant->requireId()],
        };

        Setting::query()->updateOrCreate(
            ['scope_type' => $type, 'scope_id' => $id, 'group' => $group, 'key' => $key],
            ['value' => $value],
        );
    }
}
