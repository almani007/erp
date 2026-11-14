<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Validation;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Shared\Domain\Contract\TenantContext;

/**
 * Tenant-aware replacements for the plain `exists:` / `unique:` rules,
 * which query the table directly and would otherwise see other tenants' rows.
 */
final class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('tenant_id', app(TenantContext::class)->requireId());
    }

    public static function unique(string $table, string $column, ?string $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column)
            ->where('tenant_id', app(TenantContext::class)->requireId());

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }
}
