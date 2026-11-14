<?php

declare(strict_types=1);

namespace Saas\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Saas\Application\ApplyBusinessTemplate;
use Saas\Application\Entitlements;
use Saas\Application\ProvisionTenant;
use Saas\Infrastructure\Persistence\Eloquent\BusinessTemplate;
use Shared\Domain\Contract\AuditTrail;

/**
 * Platform operator: every tenant on the system. Reads go straight to the
 * tables because these are cross-tenant by design.
 */
final class TenantAdminController
{
    public function index(): JsonResponse
    {
        $users = DB::table('users')->selectRaw('tenant_id, count(*) as c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
        $branches = DB::table('branches')->selectRaw('tenant_id, count(*) as c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
        $sales = DB::table('sales')->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('tenant_id, count(*) as c')->groupBy('tenant_id')->pluck('c', 'tenant_id');

        $rows = DB::table('tenants')->orderBy('legal_name')->get()->map(fn ($t): array => [
            'id' => $t->id,
            'slug' => $t->slug,
            'legal_name' => $t->legal_name,
            'status' => $t->status,
            'default_currency' => $t->default_currency,
            'business_template' => $t->business_template,
            'entitlements' => $t->entitlements === null ? null : json_decode((string) $t->entitlements, true),
            'users' => (int) ($users[$t->id] ?? 0),
            'branches' => (int) ($branches[$t->id] ?? 0),
            'sales_30d' => (int) ($sales[$t->id] ?? 0),
            'created_at' => $t->created_at,
        ]);

        return new JsonResponse(['data' => $rows]);
    }

    public function store(Request $request, ProvisionTenant $provision, AuditTrail $audit): JsonResponse
    {
        $request->merge(['owner_email' => mb_strtolower((string) $request->input('owner_email'))]);
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('tenants', 'slug')],
            'legal_name' => ['required', 'string', 'max:200'],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'template' => ['nullable', 'string', Rule::exists('business_templates', 'code')],
            'owner_name' => ['required', 'string', 'max:150'],
            'owner_email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'owner_password' => ['required', 'string', Password::min(10)],
        ] + $this->entitlementRules());

        $template = isset($data['template']) ? BusinessTemplate::query()->where('code', $data['template'])->first() : null;
        $data['currency'] = strtoupper($data['currency']);
        $data['entitlements'] = $this->entitlements($data);

        $tenant = $provision->execute($data, $template);

        $audit->record('saas.tenant.provisioned', 'tenant', $tenant->id, null, ['slug' => $tenant->slug, 'template' => $data['template'] ?? null]);

        return new JsonResponse(['data' => ['id' => $tenant->id, 'slug' => $tenant->slug]], 201);
    }

    /** Suspend / reactivate, and change what the tenant's plan includes. */
    public function update(Request $request, string $tenantId, AuditTrail $audit): JsonResponse
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first() ?? abort(404);
        abort_if($tenant->slug === 'platform', 422, 'The platform tenant cannot be changed here.');

        $data = $request->validate(['status' => ['sometimes', 'in:active,suspended']] + $this->entitlementRules());

        $changes = ['updated_at' => now()];
        if (isset($data['status'])) {
            $changes['status'] = $data['status'];
        }
        if (array_intersect_key($data, array_flip(['modules', 'features', 'max_users', 'max_branches'])) !== []) {
            $changes['entitlements'] = json_encode($this->entitlements($data), JSON_THROW_ON_ERROR);
        }

        DB::table('tenants')->where('id', $tenantId)->update($changes);

        if (($data['status'] ?? null) === 'suspended') {
            // Signed-in users are cut off at once rather than when their token expires.
            DB::table('personal_access_tokens')->where('tokenable_type', 'like', '%User')
                ->whereIn('tokenable_id', DB::table('users')->where('tenant_id', $tenantId)->select('id'))->delete();
        }

        $audit->record('saas.tenant.updated', 'tenant', $tenantId, null, array_diff_key($changes, ['updated_at' => 1]));

        return $this->index();
    }

    public function applyTemplate(Request $request, string $tenantId, ApplyBusinessTemplate $apply, AuditTrail $audit): JsonResponse
    {
        DB::table('tenants')->where('id', $tenantId)->exists() || abort(404);
        $data = $request->validate(['template' => ['required', 'string', Rule::exists('business_templates', 'code')]]);

        $template = BusinessTemplate::query()->where('code', $data['template'])->firstOrFail();
        $result = $apply->execute($tenantId, $template);

        $audit->record('saas.template.applied', 'tenant', $tenantId, null, ['template' => $template->code] + $result);

        return new JsonResponse(['data' => $result]);
    }

    /** @return array<string, mixed> */
    private function entitlementRules(): array
    {
        return [
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['boolean'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'max_branches' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * Store only restrictions: modules/features switched off and real limits.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function entitlements(array $data): array
    {
        $modules = array_intersect_key($data['modules'] ?? [], array_flip(Entitlements::MODULES));

        return array_filter([
            'modules' => array_map(static fn (): bool => false, array_filter($modules, static fn ($on): bool => ! $on)) ?: null,
            'features' => array_map(static fn (): bool => false, array_filter($data['features'] ?? [], static fn ($on): bool => ! $on)) ?: null,
            'max_users' => $data['max_users'] ?? null,
            'max_branches' => $data['max_branches'] ?? null,
        ], static fn ($v): bool => $v !== null);
    }
}
