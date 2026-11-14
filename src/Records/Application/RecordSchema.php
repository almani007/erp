<?php

declare(strict_types=1);

namespace Records\Application;

use Illuminate\Support\Str;
use Records\Infrastructure\Persistence\Eloquent\RecordType;

/**
 * Turns what an administrator typed into a clean field list, and checks a
 * record's values against it. Shared by tenant admins and business templates.
 */
final class RecordSchema
{
    /**
     * @param  list<array<string, mixed>>  $fields  as submitted: label, type, options (csv or list), required
     * @param  list<array<string, mixed>>  $existing  current fields, whose keys and types are kept
     * @return list<array{key: string, label: string, type: string, options: list<string>|null, required: bool}>
     */
    public function normaliseFields(array $fields, array $existing = []): array
    {
        $known = collect($existing)->keyBy('key');
        $clean = [];
        $used = [];

        foreach ($fields as $field) {
            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            // An existing field keeps its key and type, so stored values stay readable.
            $key = isset($field['key']) && $known->has($field['key']) ? (string) $field['key'] : self::keyFor($label);
            while (in_array($key, $used, true)) {
                $key .= '_2';
            }
            $used[] = $key;

            $type = $known->has($key) ? (string) $known[$key]['type'] : (string) ($field['type'] ?? 'text');
            if (! in_array($type, RecordType::FIELD_TYPES, true)) {
                throw new \DomainException("Field '{$label}' has an unknown type '{$type}'.");
            }

            $options = $field['options'] ?? null;
            $options = is_array($options) ? $options : explode(',', (string) $options);
            $options = array_values(array_unique(array_filter(array_map(static fn ($o): string => trim((string) $o), $options))));

            if ($type === 'select' && $options === []) {
                throw new \DomainException("Field '{$label}' is a list: give it at least one choice.");
            }

            $clean[] = [
                'key' => $key,
                'label' => Str::limit($label, 80, ''),
                'type' => $type,
                'options' => $type === 'select' ? $options : null,
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        if ($clean === []) {
            throw new \DomainException('A record type needs at least one field.');
        }

        return $clean;
    }

    /**
     * Keep only defined fields and check each value. On update, `$partial`
     * skips the required check for fields that were not sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validate(RecordType $type, array $data, bool $partial = false): array
    {
        $clean = [];

        foreach ($type->fields as $field) {
            $key = $field['key'];
            $present = array_key_exists($key, $data);
            $value = $data[$key] ?? null;
            $empty = $value === null || $value === '';

            if (! $present && $partial) {
                continue;
            }
            if ($empty) {
                if ($field['required']) {
                    throw new \DomainException("{$field['label']} is required.");
                }
                $clean[$key] = null;

                continue;
            }

            $ok = match ($field['type']) {
                'number', 'money' => is_numeric($value),
                'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
                'date', 'datetime' => is_string($value) && strtotime($value) !== false,
                'select' => in_array($value, $field['options'] ?? [], true),
                default => is_scalar($value) && mb_strlen((string) $value) <= ($field['type'] === 'longtext' ? 5000 : 500),
            };

            if (! $ok) {
                throw new \DomainException("{$field['label']} has a value that does not fit its type.");
            }

            $clean[$key] = $field['type'] === 'boolean' ? (bool) $value : $value;
        }

        return $clean;
    }

    public static function keyFor(string $label): string
    {
        $key = preg_replace('/[^a-z0-9_]/', '', Str::snake(Str::ascii($label))) ?: 'field';
        $key = Str::limit($key, 36, '');

        return preg_match('/^[a-z]/', $key) === 1 ? $key : 'f_'.$key;
    }
}
