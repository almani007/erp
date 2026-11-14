<?php

declare(strict_types=1);

namespace Finance\Application;

use Carbon\CarbonImmutable;
use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Infrastructure\Persistence\Eloquent\FxRevaluation;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Support\Decimal;

/**
 * Period-end revaluation of what is still owed in foreign currency.
 *
 * Each open foreign supplier bill is carried at the rate of the day it was
 * entered. At the period end it is restated at that day's rate:
 *
 *   owe more (currency rose)   Dr Unrealised exchange loss   Cr Accounts payable
 *   owe less (currency fell)   Dr Accounts payable           Cr Unrealised exchange gain
 *
 * The entry is reversed on the next day, so the bills keep their original
 * rate and the real gain or loss is booked when they are paid. Bills and
 * supplier balances are not changed.
 */
final class RevalueForeignBalances
{
    public function __construct(
        private readonly ExchangeRates $rates,
        private readonly PostJournalEntry $postJournal,
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly PostingAccounts $accounts,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * What a revaluation would post, without posting it.
     *
     * @return array{lines: list<array<string, string|null>>, net_adjustment: string, missing_rates: list<string>}
     */
    public function preview(string $companyId, string $asOf): array
    {
        $bills = DB::table('supplier_bills as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')
            ->where('b.company_id', $companyId)->whereNotNull('b.currency')->where('b.balance_due', '>', 0)
            ->where('b.bill_date', '<=', $asOf)
            ->orderBy('b.currency')->orderBy('b.bill_no')
            ->get(['b.id', 'b.bill_no', 'b.supplier_id', 's.name as supplier', 'b.currency', 'b.fx_rate', 'b.balance_due', 'b.foreign_balance_due']);

        $lines = [];
        $missing = [];
        $net = '0';
        foreach ($bills as $b) {
            $rate = $this->rates->find($companyId, $b->currency, $asOf);
            if ($rate === null) {
                $missing[$b->currency] = $b->currency;

                continue;
            }
            $revalued = ExchangeRates::toBase((string) $b->foreign_balance_due, $rate);
            $diff = Decimal::sub($revalued, (string) $b->balance_due, 2);
            if (Decimal::isZero($diff)) {
                continue;
            }
            $lines[] = [
                'document' => 'supplier_bill', 'id' => $b->id, 'number' => $b->bill_no, 'party_id' => $b->supplier_id, 'party' => $b->supplier,
                'currency' => $b->currency, 'open_foreign' => Decimal::round((string) $b->foreign_balance_due, 2),
                'booked_rate' => (string) $b->fx_rate, 'closing_rate' => $rate,
                'carried' => Decimal::round((string) $b->balance_due, 2), 'revalued' => $revalued, 'difference' => $diff,
            ];
            $net = Decimal::add($net, $diff, 2);
        }

        return ['lines' => $lines, 'net_adjustment' => $net, 'missing_rates' => array_values($missing)];
    }

    public function run(string $companyId, string $asOf): FxRevaluation
    {
        return DB::transaction(function () use ($companyId, $asOf): FxRevaluation {
            if (FxRevaluation::query()->where('company_id', $companyId)->where('as_of', $asOf)->exists()) {
                throw new \DomainException("Foreign balances were already revalued at {$asOf}.");
            }
            $preview = $this->preview($companyId, $asOf);
            if ($preview['missing_rates'] !== []) {
                throw new \DomainException('Enter exchange rates for '.implode(', ', $preview['missing_rates'])." on or before {$asOf} first.");
            }
            if ($preview['lines'] === []) {
                throw new \DomainException('Nothing to revalue: no open foreign-currency balances have moved.');
            }

            $ap = $this->accounts->id($companyId, 'accounts_payable');
            $fx = $this->accounts->id($companyId, 'fx_unrealized');
            $journalLines = [];
            $bySupplier = [];
            foreach ($preview['lines'] as $l) {
                $bySupplier[$l['party_id']] = Decimal::add($bySupplier[$l['party_id']] ?? '0', $l['difference'], 2);
            }
            foreach ($bySupplier as $supplierId => $diff) {
                if (Decimal::isPositive($diff)) {
                    $journalLines[] = JournalLineDraft::debit($fx, $diff, null, 'Unrealised exchange loss');
                    $journalLines[] = JournalLineDraft::credit($ap, $diff, null, 'Revaluation', 'supplier', $supplierId);
                } elseif (Decimal::isNegative($diff)) {
                    $gain = Decimal::negate($diff, 2);
                    $journalLines[] = JournalLineDraft::debit($ap, $gain, null, 'Revaluation', 'supplier', $supplierId);
                    $journalLines[] = JournalLineDraft::credit($fx, $gain, null, 'Unrealised exchange gain');
                }
            }

            $reval = FxRevaluation::query()->create([
                'company_id' => $companyId, 'as_of' => $asOf, 'net_adjustment' => $preview['net_adjustment'],
                'lines' => $preview['lines'], 'created_by' => $this->auth->guard('sanctum')->id(),
            ]);
            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $companyId, postingDate: $asOf, sourceType: 'fx_revaluation', sourceId: $reval->id,
                description: "Revaluation of foreign balances at {$asOf}", lines: $journalLines,
            ));
            $reversal = $this->reverseJournal->execute($journal->id, "automatic reversal of revaluation at {$asOf}",
                CarbonImmutable::parse($asOf)->addDay()->toDateString());

            $reval->update(['journal_entry_id' => $journal->id, 'reversal_journal_entry_id' => $reversal->id]);
            $this->audit->record('finance.fx.revalued', 'fx_revaluation', $reval->id, null, ['as_of' => $asOf, 'net' => $preview['net_adjustment']]);

            return $reval->refresh();
        });
    }
}
