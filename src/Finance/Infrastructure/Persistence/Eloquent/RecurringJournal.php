<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class RecurringJournal extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'recurring_journals';

    protected $fillable = ['company_id', 'name', 'description', 'lines', 'frequency', 'next_run_date', 'end_date', 'status', 'runs_count', 'last_run_at', 'last_journal_id', 'last_error', 'created_by'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'next_run_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'last_run_at' => 'datetime'];
    }
}
