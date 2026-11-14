<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class CustomField extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public const array ENTITIES = ['product', 'customer', 'supplier'];

    public const array TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    protected $table = 'custom_fields';

    protected $fillable = ['entity', 'key', 'label', 'type', 'options', 'sort_order', 'status'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
