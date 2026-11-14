<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Shared\Infrastructure\Concern\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class TaxCode extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'tax_codes';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'tenant_id', 'code', 'name', 'rate', 'type', 'status'];
}
