<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasCustomFields;
use Shared\Infrastructure\Concern\HasUuid;

final class Supplier extends Model
{
    use BelongsToTenant;
    use HasCustomFields;
    use HasUuid;

    protected $table = 'suppliers';

    protected $fillable = ['supplier_code', 'name', 'contact_person', 'phone', 'email', 'tax_no', 'payment_terms_days', 'status', 'address'];

    protected function casts(): array
    {
        return ['custom' => 'array'];
    }
}
