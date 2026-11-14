<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Finance\Domain\Enum\JournalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

/** Immutable once posted — enforced by a database trigger, not just here. */
final class JournalEntry extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'journal_entries';

    protected $fillable = [
        'company_id', 'journal_no', 'source_type', 'source_id', 'posting_date', 'status', 'currency',
        'description', 'reverses_entry_id', 'reversed_by_entry_id', 'posted_at', 'posted_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => JournalStatus::class,
            'posting_date' => 'date:Y-m-d',
            'posted_at' => 'datetime',
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('id');
    }
}
