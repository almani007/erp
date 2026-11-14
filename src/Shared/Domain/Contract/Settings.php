<?php

declare(strict_types=1);

namespace Shared\Domain\Contract;

interface Settings
{
    /**
     * Read a setting for the current tenant, cascading branch → company → tenant.
     */
    public function get(string $group, string $key, ?string $companyId = null, ?string $branchId = null, mixed $default = null): mixed;

    /**
     * Store a setting at the narrowest scope given (branch, else company, else tenant).
     */
    public function set(string $group, string $key, mixed $value, ?string $companyId = null, ?string $branchId = null): void;
}
