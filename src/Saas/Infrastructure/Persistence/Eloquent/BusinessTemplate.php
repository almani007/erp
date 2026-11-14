<?php

declare(strict_types=1);

namespace Saas\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

/** Platform-wide: not owned by any tenant. */
final class BusinessTemplate extends Model
{
    use HasUuid;

    protected $table = 'business_templates';

    protected $fillable = ['code', 'name', 'description', 'features', 'custom_fields', 'record_types', 'status'];

    protected function casts(): array
    {
        return ['features' => 'array', 'custom_fields' => 'array', 'record_types' => 'array'];
    }
}
