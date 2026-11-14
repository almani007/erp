<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Context;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Shared\Domain\Contract\TenantContext;

/**
 * The tenant is always derived from the authenticated user — never from a
 * header, query string or request body — so a caller cannot ask to act as
 * another tenant. Console commands, seeders and queued jobs set it explicitly.
 */
final class RequestTenantContext implements TenantContext
{
    private ?string $tenantId = null;

    public function __construct(private readonly AuthFactory $auth) {}

    public function id(): ?string
    {
        if ($this->tenantId !== null) {
            return $this->tenantId;
        }

        $user = $this->auth->guard('sanctum')->user();
        $tenantId = $user?->getAttribute('tenant_id');

        return is_string($tenantId) ? $tenantId : null;
    }

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function requireId(): string
    {
        return $this->id() ?? throw new \RuntimeException('Tenant context has not been resolved.');
    }
}
