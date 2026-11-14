<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Finance\Domain\Enum\PeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class AccountingPeriod extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'accounting_periods';

    protected $fillable = ['company_id', 'name', 'start_date', 'end_date', 'status', 'closed_at', 'closed_by'];

    protected function casts(): array
    {
        return [
            'status' => PeriodStatus::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
        ];
    }
}
