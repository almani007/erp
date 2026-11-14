<?php

declare(strict_types=1);

namespace Finance\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Infrastructure\Persistence\Eloquent\AccountingPeriod;
use Finance\Infrastructure\Persistence\Eloquent\YearEndClosing;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Support\Decimal;

/**
 * Year-end close: every revenue and expense account's balance for the
 * year is moved to retained earnings in one journal on the last day, then
 * the year's accounting periods are closed so nothing more posts into them.
 * Reopening reverses that journal and reopens the periods.
 */
final class CloseFinancialYear
{
    public function __construct(
        private readonly PostJournalEntry $postJournal,
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly PostingAccounts $accounts,
        private readonly AuditTrail $audit,
    ) {}

    public function close(string $companyId, string $yearStart, string $yearEnd, string $actorId): YearEndClosing
    {
        return DB::transaction(function () use ($companyId, $yearStart, $yearEnd, $actorId): YearEndClosing {
            if (YearEndClosing::query()->where('company_id', $companyId)->where('status', 'closed')->where('year_end', '>=', $yearStart)->where('year_start', '<=', $yearEnd)->exists()) {
                throw new \DomainException('This year overlaps one that is already closed.');
            }

            $balances = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('e.company_id', $companyId)->whereIn('e.status', ['posted', 'reversed'])
                ->whereBetween('e.posting_date', [$yearStart, $yearEnd])
                ->whereIn('a.account_type', ['revenue', 'expense'])
                ->groupBy('l.account_id', 'l.branch_id')
                ->selectRaw('l.account_id, l.branch_id, sum(l.debit) - sum(l.credit) as balance')
                ->get();

            $lines = [];
            $net = '0'; // credit-positive: profit
            foreach ($balances as $b) {
                $bal = Decimal::round((string) $b->balance, 2);
                if (Decimal::isZero($bal)) {
                    continue;
                }
                // Debit-balance (expense) accounts are credited to zero, and the other way round.
                $lines[] = Decimal::isPositive($bal)
                    ? JournalLineDraft::credit($b->account_id, $bal, $b->branch_id, 'Year-end close')
                    : JournalLineDraft::debit($b->account_id, Decimal::negate($bal, 2), $b->branch_id, 'Year-end close');
                $net = Decimal::sub($net, $bal, 2);
            }

            if ($lines === []) {
                throw new \DomainException('There is no income or expense to close in this year.');
            }

            $retained = $this->accounts->id($companyId, 'retained_earnings');
            $lines[] = Decimal::isNegative($net)
                ? JournalLineDraft::debit($retained, Decimal::negate($net, 2), null, 'Loss for the year')
                : JournalLineDraft::credit($retained, $net, null, 'Profit for the year');

            $closing = YearEndClosing::query()->create([
                'company_id' => $companyId, 'year_start' => $yearStart, 'year_end' => $yearEnd,
                'net_income' => $net, 'status' => 'closed', 'created_by' => $actorId,
            ]);

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $companyId, postingDate: $yearEnd, sourceType: 'year_end_close', sourceId: $closing->id,
                description: "Year-end close {$yearStart} – {$yearEnd}", lines: $lines,
            ));
            $closing->update(['journal_entry_id' => $journal->id]);

            AccountingPeriod::query()->where('company_id', $companyId)
                ->where('start_date', '>=', $yearStart)->where('end_date', '<=', $yearEnd)->update(['status' => 'closed']);

            $this->audit->record('finance.year.closed', 'year_end_closing', $closing->id, null, ['year_end' => $yearEnd, 'net_income' => $net]);

            return $closing;
        });
    }

    public function reopen(string $closingId, string $reason): YearEndClosing
    {
        return DB::transaction(function () use ($closingId, $reason): YearEndClosing {
            $closing = YearEndClosing::query()->lockForUpdate()->findOrFail($closingId);
            if ($closing->status !== 'closed') {
                throw new \DomainException('This year is not closed.');
            }
            if (YearEndClosing::query()->where('company_id', $closing->company_id)->where('status', 'closed')->where('year_end', '>', $closing->year_end)->exists()) {
                throw new \DomainException('Reopen the later years first.');
            }

            // The reversal posts on the closing date, so that period must be open first.
            AccountingPeriod::query()->where('company_id', $closing->company_id)
                ->where('start_date', '>=', $closing->year_start->toDateString())->where('end_date', '<=', $closing->year_end->toDateString())
                ->update(['status' => 'open']);
            $this->reverseJournal->execute($closing->journal_entry_id, $reason, $closing->year_end->toDateString());

            $closing->update(['status' => 'reopened']);
            $this->audit->record('finance.year.reopened', 'year_end_closing', $closing->id, null, ['year_end' => $closing->year_end->toDateString()], $reason);

            return $closing;
        });
    }
}
