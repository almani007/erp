<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class FxRevaluation extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'fx_revaluations';

    protected $fillable = ['company_id', 'as_of', 'net_adjustment', 'lines', 'journal_entry_id', 'reversal_journal_entry_id', 'created_by'];

    protected function casts(): array
    {
        return ['as_of' => 'date:Y-m-d', 'lines' => 'array'];
    }
}
