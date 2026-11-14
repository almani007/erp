<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class YearEndClosing extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'year_end_closings';

    protected $fillable = ['company_id', 'year_start', 'year_end', 'net_income', 'journal_entry_id', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['year_start' => 'date:Y-m-d', 'year_end' => 'date:Y-m-d'];
    }
}
