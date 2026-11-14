<?php

declare(strict_types=1);

namespace Banking\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Cheque extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'cheques';

    protected $fillable = ['company_id', 'direction', 'bank_account_id', 'party_type', 'party_id', 'cheque_no', 'drawer_bank', 'cheque_date', 'amount', 'status', 'deposited_on', 'cleared_on', 'payment_id', 'bounce_reason', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['cheque_date' => 'date:Y-m-d', 'deposited_on' => 'date:Y-m-d', 'cleared_on' => 'date:Y-m-d'];
    }
}
