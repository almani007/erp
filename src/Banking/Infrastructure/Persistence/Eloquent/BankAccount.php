<?php

declare(strict_types=1);

namespace Banking\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class BankAccount extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'bank_accounts';

    protected $fillable = ['company_id', 'account_id', 'kind', 'name', 'bank_name', 'branch_name', 'account_no', 'iban', 'is_default', 'status'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
