<?php

declare(strict_types=1);

namespace Finance\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Domain\Enum\JournalStatus;
use Finance\Domain\Exception\ImmutableRecord;
use Finance\Infrastructure\Persistence\Eloquent\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * Corrections never edit history: the original stays, a mirror entry is
 * posted, and the two are linked (FIN-009).
 */
final class ReverseJournalEntry
{
    public function __construct(private readonly PostJournalEntry $post) {}

    /** @param  string  $reversalDate  Y-m-d */
    public function execute(string $journalEntryId, string $reason, string $reversalDate): JournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $reason, $reversalDate): JournalEntry {
            $original = JournalEntry::query()->lockForUpdate()->findOrFail($journalEntryId);

            if ($original->status !== JournalStatus::Posted) {
                throw ImmutableRecord::journalAlreadyReversed($original->journal_no);
            }

            // Mirror every line onto the opposite side.
            $lines = [];
            foreach ($original->lines as $line) {
                $lines[] = bccomp((string) $line->debit, '0', 4) > 0
                    ? JournalLineDraft::credit($line->account_id, (string) $line->debit, $line->branch_id, $line->description, $line->party_type, $line->party_id)
                    : JournalLineDraft::debit($line->account_id, (string) $line->credit, $line->branch_id, $line->description, $line->party_type, $line->party_id);
            }

            $reversal = $this->post->execute(new JournalDraft(
                companyId: $original->company_id,
                postingDate: $reversalDate,
                sourceType: 'reversal',
                sourceId: $original->id,
                description: "Reversal of {$original->journal_no}: {$reason}",
                lines: $lines,
                reversesEntryId: $original->id,
            ));

            $original->update(['status' => JournalStatus::Reversed, 'reversed_by_entry_id' => $reversal->id]);

            return $reversal;
        });
    }
}
