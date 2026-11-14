<?php

declare(strict_types=1);

namespace Saas\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Records\Application\RecordSchema;
use Saas\Application\ApplyBusinessTemplate;
use Saas\Infrastructure\Persistence\Eloquent\BusinessTemplate;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\TenantContext;

/** Platform operator: the starting shapes offered to new businesses. */
final class BusinessTemplateController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => BusinessTemplate::query()->orderBy('name')->get()]);
    }

    public function store(Request $request, RecordSchema $schema): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('business_templates', 'code')],
            'name' => ['required', 'string', 'max:100'],
        ]);

        return new JsonResponse(['data' => BusinessTemplate::query()->create($this->clean($data, $schema) + ['status' => 'active'])], 201);
    }

    public function update(Request $request, string $templateId, RecordSchema $schema): JsonResponse
    {
        $template = BusinessTemplate::query()->findOrFail($templateId);
        $data = $request->validate($this->rules() + [
            'name' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $template->update($this->clean($data, $schema));

        return new JsonResponse(['data' => $template]);
    }

    /** Active templates, as a business sees them when choosing one. */
    public function available(): JsonResponse
    {
        return new JsonResponse(['data' => BusinessTemplate::query()->where('status', 'active')->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'features', 'custom_fields', 'record_types'])]);
    }

    public function applyToOwnBusiness(string $code, ApplyBusinessTemplate $apply, TenantContext $tenant, AuditTrail $audit): JsonResponse
    {
        $template = BusinessTemplate::query()->where('code', $code)->where('status', 'active')->firstOrFail();
        $result = $apply->execute($tenant->requireId(), $template);

        $audit->record('organization.template.applied', 'tenant', $tenant->requireId(), null, ['template' => $code] + $result);

        return new JsonResponse(['data' => $result]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:500'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
            'custom_fields' => ['sometimes', 'nullable', 'array', 'max:60'],
            'custom_fields.*.entity' => ['required', 'in:product,customer,supplier'],
            'custom_fields.*.label' => ['required', 'string', 'max:80'],
            'custom_fields.*.type' => ['required', 'in:text,number,date,boolean,select'],
            'custom_fields.*.options' => ['nullable'],
            'record_types' => ['sometimes', 'nullable', 'array', 'max:20'],
            'record_types.*.name' => ['required', 'string', 'max:80'],
            'record_types.*.plural' => ['nullable', 'string', 'max:80'],
            'record_types.*.prefix' => ['nullable', 'string', 'max:10', 'alpha_num'],
            'record_types.*.statuses' => ['nullable'],
            'record_types.*.link_customer' => ['sometimes', 'boolean'],
            'record_types.*.fields' => ['required', 'array', 'min:1', 'max:60'],
        ];
    }

    /**
     * Record type fields are checked the same way a tenant's own are, so a
     * template can never hand a tenant a schema it could not have built.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data, RecordSchema $schema): array
    {
        foreach ($data['record_types'] ?? [] as $i => $type) {
            $data['record_types'][$i]['fields'] = $schema->normaliseFields($type['fields']);
        }

        return $data;
    }
}
