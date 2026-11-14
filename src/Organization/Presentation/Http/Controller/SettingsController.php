<?php

declare(strict_types=1);

namespace Organization\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shared\Domain\Contract\Settings;
use Shared\Infrastructure\Validation\TenantRule;

final class SettingsController
{
    public function show(Request $request, Settings $settings): JsonResponse
    {
        $q = $request->validate([
            'group' => ['required', 'string', 'max:80'],
            'key' => ['required', 'string', 'max:120'],
            'company_id' => ['nullable', 'uuid', TenantRule::exists('companies')],
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
        ]);

        return new JsonResponse(['data' => [
            'group' => $q['group'],
            'key' => $q['key'],
            'value' => $settings->get($q['group'], $q['key'], $q['company_id'] ?? null, $q['branch_id'] ?? null),
        ]]);
    }

    public function update(Request $request, Settings $settings): JsonResponse
    {
        $v = $request->validate([
            'group' => ['required', 'string', 'max:80'],
            'key' => ['required', 'string', 'max:120'],
            'value' => ['present'],
            'company_id' => ['nullable', 'uuid', TenantRule::exists('companies')],
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
        ]);

        $settings->set($v['group'], $v['key'], $v['value'], $v['company_id'] ?? null, $v['branch_id'] ?? null);

        return new JsonResponse(['data' => ['group' => $v['group'], 'key' => $v['key'], 'value' => $v['value']]]);
    }
}
