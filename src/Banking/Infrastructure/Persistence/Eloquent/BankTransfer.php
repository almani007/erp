<?php

declare(strict_types=1);

namespace Banking\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class BankTransfer extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'bank_transfers';

    protected $fillable = ['company_id', 'transfer_no', 'from_account_id', 'to_account_id', 'transfer_date', 'amount', 'reference', 'journal_entry_id', 'created_by'];

    protected function casts(): array
    {
        return ['transfer_date' => 'date:Y-m-d'];
    }
}
