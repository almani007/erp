<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Finance\Domain\Enum\AccountType;
use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Account extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'chart_of_accounts';

    protected $fillable = ['company_id', 'code', 'name', 'account_type', 'parent_id', 'is_control_account', 'is_system', 'status'];

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'is_control_account' => 'boolean',
            'is_system' => 'boolean',
        ];
    }
}
