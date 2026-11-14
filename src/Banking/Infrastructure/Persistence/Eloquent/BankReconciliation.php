<?php

declare(strict_types=1);

namespace Banking\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class BankReconciliation extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'bank_reconciliations';

    protected $fillable = ['bank_account_id', 'statement_date', 'statement_balance', 'status', 'created_by', 'completed_by', 'completed_at'];

    protected function casts(): array
    {
        return ['statement_date' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }
}
