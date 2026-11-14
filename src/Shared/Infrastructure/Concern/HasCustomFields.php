<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Concern;

/**
 * Tenant-defined extra fields, stored in a `custom` JSON column.
 * The model must cast `custom` to array.
 */
trait HasCustomFields
{
    /** Validation rules for a `custom` object in a request. */
    public static function customFieldRules(): array
    {
        return [
            'custom' => ['sometimes', 'array', 'max:60'],
            'custom.*' => ['nullable', 'max:500'],
        ];
    }

    /**
     * Merge submitted values into the stored ones (a partial update must not
     * wipe the fields it did not mention). Null or empty removes a value.
     *
     * @param  array<string, mixed>|null  $values
     */
    public function mergeCustom(?array $values): void
    {
        if ($values === null) {
            return;
        }

        $custom = $this->getAttribute('custom') ?? [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1 || is_array($value)) {
                continue;
            }
            if ($value === null || $value === '') {
                unset($custom[$key]);
            } else {
                $custom[$key] = $value;
            }
        }

        $this->setAttribute('custom', $custom === [] ? null : $custom);
    }
}
