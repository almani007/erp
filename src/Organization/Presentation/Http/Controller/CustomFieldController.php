<?php

declare(strict_types=1);

namespace Organization\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Organization\Infrastructure\Persistence\Eloquent\CustomField;

final class CustomFieldController
{
    public function index(Request $request): JsonResponse
    {
        $entity = $request->validate(['entity' => ['nullable', Rule::in(CustomField::ENTITIES)]])['entity'] ?? null;

        $fields = CustomField::query()
            ->when($entity, fn ($q, $e) => $q->where('entity', $e))
            ->orderBy('entity')->orderBy('sort_order')->orderBy('label')
            ->get();

        return new JsonResponse(['data' => $fields]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', Rule::in(CustomField::ENTITIES)],
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(CustomField::TYPES)],
            'options' => ['nullable', 'string', 'max:2000'], // comma-separated choices
            'sort_order' => ['sometimes', 'integer'],
        ]);

        // The key is derived once and never changes, so stored values stay attached.
        $key = Str::limit(Str::snake(Str::ascii($data['label'])), 40, '');
        $key = preg_replace('/[^a-z0-9_]/', '', $key) ?: 'field';
        if (preg_match('/^[a-z]/', $key) !== 1) {
            $key = 'f_'.$key;
        }

        if (CustomField::query()->where('entity', $data['entity'])->where('key', $key)->exists()) {
            throw new \DomainException('A field with this name already exists for that record type.');
        }

        $field = CustomField::query()->create([
            'entity' => $data['entity'],
            'key' => $key,
            'label' => $data['label'],
            'type' => $data['type'],
            'options' => $this->options($data['options'] ?? null),
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => 'active',
        ]);

        return new JsonResponse(['data' => $field], 201);
    }

    public function update(Request $request, string $fieldId): JsonResponse
    {
        $field = CustomField::findOrFail($fieldId);

        // Entity, key and type are fixed: changing them would orphan or misread saved values.
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:80'],
            'options' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        if (array_key_exists('options', $data)) {
            $data['options'] = $this->options($data['options']);
        }

        $field->update($data);

        return new JsonResponse(['data' => $field]);
    }

    /** @return list<string>|null */
    private function options(?string $csv): ?array
    {
        $options = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $csv)))));

        return $options === [] ? null : $options;
    }
}
