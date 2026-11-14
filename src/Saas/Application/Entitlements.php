<?php

declare(strict_types=1);

namespace Saas\Application;

use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\TenantContext;

/**
 * What the platform operator allows the current tenant to use. Anything
 * not mentioned is allowed, so a tenant with no entitlements has everything.
 */
final class Entitlements
{
    public const array MODULES = ['sales', 'catalog', 'inventory', 'purchasing', 'finance', 'reporting', 'records', 'production', 'people', 'assets', 'restaurant', 'bookings', 'rentals'];

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    private ?string $tenantStatus = null;

    public function __construct(private readonly TenantContext $tenant) {}

    public function tenantActive(): bool
    {
        $this->load();

        return $this->tenantStatus === 'active';
    }

    public function moduleEnabled(string $module): bool
    {
        return ($this->load()['modules'][$module] ?? true) !== false;
    }

    public function featureAllowed(string $feature): bool
    {
        return ($this->load()['features'][$feature] ?? true) !== false;
    }

    /** A numeric limit such as max_users / max_branches, or null when unlimited. */
    public function limit(string $key): ?int
    {
        $value = $this->load()[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @throws \DomainException when adding one more would pass the plan's limit */
    public function assertWithinLimit(string $key, int $currentCount, string $what): void
    {
        $limit = $this->limit($key);

        if ($limit !== null && $currentCount >= $limit) {
            throw new \DomainException("Your plan allows {$limit} {$what}. Contact your provider to raise the limit.");
        }
    }

    /** @return array{modules: array<string, bool>, features: array<string, bool>} */
    public function summary(): array
    {
        $modules = [];
        foreach (self::MODULES as $module) {
            $modules[$module] = $this->moduleEnabled($module);
        }

        return ['modules' => $modules, 'features' => array_filter($this->load()['features'] ?? [], static fn ($v): bool => $v === false) ];
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if ($this->loaded === null) {
            $row = DB::table('tenants')->where('id', $this->tenant->requireId())->first(['status', 'entitlements']);
            $this->tenantStatus = $row?->status;
            $this->loaded = $row?->entitlements === null ? [] : (json_decode((string) $row->entitlements, true) ?: []);
        }

        return $this->loaded;
    }
}
