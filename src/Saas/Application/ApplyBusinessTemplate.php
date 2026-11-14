<?php

declare(strict_types=1);

namespace Saas\Application;

use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\CustomField;
use Records\Application\RecordSchema;
use Records\Infrastructure\Persistence\Eloquent\RecordType;
use Saas\Infrastructure\Persistence\Eloquent\BusinessTemplate;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;

/**
 * Shapes a tenant for a kind of business: sets its business type and
 * feature switches and adds the template's custom fields and record types.
 *
 * Purely additive — fields and record types the tenant already has are
 * left alone, and nothing is ever removed — so it is safe to apply again
 * or to apply a second template on top.
 */
final class ApplyBusinessTemplate
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Settings $settings,
        private readonly RecordSchema $schema,
    ) {}

    /** @return array{custom_fields_added: int, record_types_added: int} */
    public function execute(string $tenantId, BusinessTemplate $template): array
    {
        // Everything below is created inside the target tenant; the caller's
        // own tenant is restored afterwards (the operator works from another).
        $previous = $this->tenant->id();
        $this->tenant->set($tenantId);

        try {
            return $this->apply($tenantId, $template);
        } finally {
            if ($previous !== null) {
                $this->tenant->set($previous);
            }
        }
    }

    /** @return array{custom_fields_added: int, record_types_added: int} */
    private function apply(string $tenantId, BusinessTemplate $template): array
    {
        return DB::transaction(function () use ($tenantId, $template): array {
            $this->settings->set('business', 'type', $template->code);
            $this->settings->set('business', 'features', $template->features);

            $fieldsAdded = 0;
            foreach ($template->custom_fields ?? [] as $i => $field) {
                $key = RecordSchema::keyFor((string) $field['label']);

                if (CustomField::query()->where('entity', $field['entity'])->where('key', $key)->exists()) {
                    continue;
                }

                $options = $field['options'] ?? null;
                CustomField::query()->create([
                    'entity' => $field['entity'],
                    'key' => $key,
                    'label' => $field['label'],
                    'type' => $field['type'] ?? 'text',
                    'options' => is_string($options) ? array_values(array_filter(array_map('trim', explode(',', $options)))) : $options,
                    'sort_order' => $i,
                    'status' => 'active',
                ]);
                $fieldsAdded++;
            }

            $typesAdded = 0;
            foreach ($template->record_types ?? [] as $definition) {
                $key = RecordSchema::keyFor((string) $definition['name']);

                if (RecordType::query()->where('key', $key)->exists()) {
                    continue;
                }

                $statuses = $definition['statuses'] ?? null;
                RecordType::query()->create([
                    'key' => $key,
                    'name' => $definition['name'],
                    'plural' => $definition['plural'] ?? $definition['name'].'s',
                    'number_prefix' => strtoupper((string) ($definition['prefix'] ?? substr($key, 0, 3))),
                    'fields' => $this->schema->normaliseFields($definition['fields'] ?? []),
                    'statuses' => is_string($statuses) ? array_values(array_filter(array_map('trim', explode(',', $statuses)))) : $statuses,
                    'link_customer' => (bool) ($definition['link_customer'] ?? false),
                    'status' => 'active',
                ]);
                $typesAdded++;
            }

            DB::table('tenants')->where('id', $tenantId)->update(['business_template' => $template->code, 'updated_at' => now()]);

            return ['custom_fields_added' => $fieldsAdded, 'record_types_added' => $typesAdded];
        });
    }
}
