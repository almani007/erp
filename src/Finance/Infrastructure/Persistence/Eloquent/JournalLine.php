<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Infrastructure\Concern\BelongsToTenant;

final class JournalLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'journal_lines';

    protected $fillable = ['journal_entry_id', 'account_id', 'debit', 'credit', 'branch_id', 'party_type', 'party_id', 'description'];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
