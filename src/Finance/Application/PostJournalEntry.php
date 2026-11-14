<?php

declare(strict_types=1);

namespace Finance\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Domain\Enum\JournalStatus;
use Finance\Domain\Exception\PeriodClosedException;
use Finance\Domain\Exception\UnbalancedJournal;
use Finance\Infrastructure\Persistence\Eloquent\Account;
use Finance\Infrastructure\Persistence\Eloquent\AccountingPeriod;
use Finance\Infrastructure\Persistence\Eloquent\JournalEntry;
use Finance\Infrastructure\Persistence\Eloquent\JournalLine;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * The one way anything reaches the general ledger.
 *
 * Sales, stock adjustments and manual vouchers all build a JournalDraft and
 * hand it here. Run it inside the caller's transaction so the business
 * document and its accounting commit or roll back together.
 */
final class PostJournalEntry
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuthFactory $auth,
    ) {}

    public function execute(JournalDraft $draft): JournalEntry
    {
        // Lines that net to nothing (e.g. zero tax) carry no information.
        $lines = array_values(array_filter(
            $draft->lines,
            static fn (JournalLineDraft $line): bool => Decimal::isPositive($line->amount()),
        ));

        $this->assertBalanced($lines);
        $this->assertPeriodOpen($draft->companyId, $draft->postingDate);
        $this->assertAccountsUsable($draft->companyId, $lines);

        return DB::transaction(function () use ($draft, $lines): JournalEntry {
            $company = Company::query()->findOrFail($draft->companyId);
            $actorId = $this->auth->guard('sanctum')->id();

            $entry = JournalEntry::query()->create([
                'company_id' => $company->id,
                'journal_no' => $this->numbers->next($this->tenant->requireId(), 'journal', $company->id, 'JV'),
                'source_type' => $draft->sourceType,
                'source_id' => $draft->sourceId,
                'posting_date' => $draft->postingDate,
                'status' => JournalStatus::Draft,
                'currency' => $company->base_currency,
                'description' => $draft->description,
                'reverses_entry_id' => $draft->reversesEntryId,
                'created_by' => $actorId,
            ]);

            foreach ($lines as $line) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line->accountId,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'branch_id' => $line->branchId,
                    'party_type' => $line->partyType,
                    'party_id' => $line->partyId,
                    'description' => $line->description,
                ]);
            }

            // The database re-checks the balance on this transition.
            $entry->update(['status' => JournalStatus::Posted, 'posted_at' => now(), 'posted_by' => $actorId]);

            return $entry;
        });
    }

    /** @param  list<JournalLineDraft>  $lines */
    private function assertBalanced(array $lines): void
    {
        $debits = '0';
        $credits = '0';

        foreach ($lines as $line) {
            if (Decimal::isNegative($line->debit) || Decimal::isNegative($line->credit)) {
                throw new \DomainException('Journal line amounts cannot be negative.');
            }
            $debits = Decimal::add($debits, $line->debit);
            $credits = Decimal::add($credits, $line->credit);
        }

        if (count($lines) < 2 || Decimal::isZero($debits) || Decimal::cmp($debits, $credits) !== 0) {
            throw UnbalancedJournal::create($debits, $credits);
        }
    }

    private function assertPeriodOpen(string $companyId, string $postingDate): void
    {
        $period = AccountingPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $postingDate)
            ->whereDate('end_date', '>=', $postingDate)
            ->first();

        // A date outside every defined period is allowed; define and close
        // periods to lock history (see Finance → Periods).
        if ($period !== null && ! $period->status->allowsPosting()) {
            throw PeriodClosedException::forDate($postingDate, $period->name);
        }
    }

    /** @param  list<JournalLineDraft>  $lines */
    private function assertAccountsUsable(string $companyId, array $lines): void
    {
        $ids = array_values(array_unique(array_map(static fn (JournalLineDraft $l): string => $l->accountId, $lines)));

        $usable = Account::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereIn('id', $ids)
            ->count();

        if ($usable !== count($ids)) {
            throw new \DomainException('One or more accounts are inactive or do not belong to this company.');
        }
    }
}
