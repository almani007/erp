<?php

declare(strict_types=1);

namespace Records\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class RecordType extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public const array FIELD_TYPES = ['text', 'longtext', 'number', 'money', 'date', 'datetime', 'boolean', 'select'];

    protected $table = 'record_types';

    protected $fillable = ['key', 'name', 'plural', 'number_prefix', 'fields', 'statuses', 'link_customer', 'status'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'statuses' => 'array', 'link_customer' => 'boolean'];
    }
}
