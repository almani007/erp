<?php

declare(strict_types=1);

namespace Records\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Records\Application\RecordSchema;
use Records\Infrastructure\Persistence\Eloquent\RecordType;

final class RecordTypeController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => RecordType::query()->orderBy('plural')->get()]);
    }

    public function store(Request $request, RecordSchema $schema): JsonResponse
    {
        $data = $request->validate($this->rules() + ['name' => ['required', 'string', 'max:80']]);

        $key = RecordSchema::keyFor($data['name']);
        if (RecordType::query()->where('key', $key)->exists()) {
            throw new \DomainException('A record type with this name already exists.');
        }

        $type = RecordType::query()->create([
            'key' => $key,
            'name' => $data['name'],
            'plural' => $data['plural'] ?? $data['name'].'s',
            'number_prefix' => strtoupper($data['number_prefix'] ?? substr($key, 0, 3)),
            'fields' => $schema->normaliseFields($data['fields']),
            'statuses' => $this->statuses($data['statuses'] ?? null),
            'link_customer' => $data['link_customer'] ?? false,
            'status' => 'active',
        ]);

        return new JsonResponse(['data' => $type], 201);
    }

    public function update(Request $request, string $typeId, RecordSchema $schema): JsonResponse
    {
        $type = RecordType::findOrFail($typeId);
        $data = $request->validate($this->rules(required: false) + [
            'name' => ['sometimes', 'string', 'max:80'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $changes = array_intersect_key($data, array_flip(['name', 'plural', 'link_customer', 'status']));
        if (isset($data['fields'])) {
            // Removing a field hides it; values already saved stay in the records.
            $changes['fields'] = $schema->normaliseFields($data['fields'], $type->fields);
        }
        if (array_key_exists('statuses', $data)) {
            $changes['statuses'] = $this->statuses($data['statuses']);
        }

        $type->update($changes);

        return new JsonResponse(['data' => $type]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $required = true): array
    {
        return [
            'plural' => ['nullable', 'string', 'max:80'],
            'number_prefix' => ['nullable', 'string', 'max:10', 'alpha_num'],
            'statuses' => ['nullable', 'string', 'max:500'], // comma separated
            'link_customer' => ['sometimes', 'boolean'],
            'fields' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:60'],
            'fields.*.key' => ['nullable', 'string', 'max:40'],
            'fields.*.label' => ['required', 'string', 'max:80'],
            'fields.*.type' => ['nullable', 'string', 'max:20'],
            'fields.*.options' => ['nullable'],
            'fields.*.required' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<string>|null */
    private function statuses(?string $csv): ?array
    {
        $statuses = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $csv)))));

        return $statuses === [] ? null : $statuses;
    }
}
