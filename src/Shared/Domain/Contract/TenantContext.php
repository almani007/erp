<?php

declare(strict_types=1);

namespace Shared\Domain\Contract;

interface TenantContext
{
    public function id(): ?string;

    public function set(string $tenantId): void;

    public function requireId(): string;
}
