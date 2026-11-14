<?php

declare(strict_types=1);

namespace Banking\Application;

use Banking\Infrastructure\Persistence\Eloquent\BankAccount;
use Banking\Infrastructure\Persistence\Eloquent\BankReconciliation;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Support\Decimal;

/**
 * Bank reconciliation: tick the ledger lines that appear on the bank
 * statement. It completes when the reconciled balance — everything ticked
 * before plus what is ticked now — equals the statement's closing balance.
 * Whatever is left unticked is the list of uncleared items.
 */
final class ReconcileBank
{
    public function __construct(private readonly AuditTrail $audit, private readonly AuthFactory $auth) {}

    public function start(string $bankAccountId, string $statementDate, string $statementBalance): BankReconciliation
    {
        $bank = BankAccount::query()->findOrFail($bankAccountId);
        $draft = BankReconciliation::query()->where('bank_account_id', $bank->id)->where('status', 'draft')->first();
        if ($draft !== null) {
            $draft->update(['statement_date' => $statementDate, 'statement_balance' => Decimal::round($statementBalance, 2)]);

            return $draft;
        }

        $last = BankReconciliation::query()->where('bank_account_id', $bank->id)->where('status', 'completed')->max('statement_date');
        if ($last !== null && $statementDate <= substr((string) $last, 0, 10)) {
            throw new \DomainException("This account is already reconciled to {$last}. Choose a later statement date.");
        }

        return BankReconciliation::query()->create([
            'bank_account_id' => $bank->id, 'statement_date' => $statementDate, 'statement_balance' => Decimal::round($statementBalance, 2),
            'status' => 'draft', 'created_by' => (string) $this->auth->guard('sanctum')->id(),
        ]);
    }

    /** @return array{reconciliation: BankReconciliation, lines: list<object>, previously_reconciled: string, ticked: string, difference: string} */
    public function workspace(string $reconciliationId): array
    {
        $rec = BankReconciliation::query()->findOrFail($reconciliationId);
        $bank = BankAccount::query()->findOrFail($rec->bank_account_id);

        // Lines not matched by an earlier completed reconciliation, up to the statement date.
        $lines = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('bank_reconciliation_lines as r', 'r.journal_line_id', '=', 'l.id')
            ->leftJoin('bank_reconciliations as rec', 'rec.id', '=', 'r.bank_reconciliation_id')
            ->where('l.account_id', $bank->account_id)
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.posting_date', '<=', $rec->statement_date->toDateString())
            ->where(fn ($q) => $q->whereNull('r.journal_line_id')->orWhere('r.bank_reconciliation_id', $rec->id))
            ->orderBy('e.posting_date')->orderBy('l.id')
            ->get(['l.id', 'e.posting_date', 'e.journal_no', 'e.source_type', 'e.description as entry', 'l.description', 'l.debit', 'l.credit',
                DB::raw('(r.bank_reconciliation_id IS NOT NULL) as ticked')])
            ->all();

        return ['reconciliation' => $rec, 'lines' => $lines] + $this->figures($rec, $bank);
    }

    /** @param  list<int>  $lineIds  the complete set ticked in this reconciliation */
    public function tick(string $reconciliationId, array $lineIds): array
    {
        return DB::transaction(function () use ($reconciliationId, $lineIds): array {
            $rec = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliationId);
            if ($rec->status !== 'draft') {
                throw new \DomainException('This reconciliation is completed.');
            }
            $bank = BankAccount::query()->findOrFail($rec->bank_account_id);

            $valid = DB::table('journal_lines')->where('account_id', $bank->account_id)->whereIn('id', $lineIds)->pluck('id')->all();
            DB::table('bank_reconciliation_lines')->where('bank_reconciliation_id', $rec->id)->delete();
            foreach (array_chunk($valid, 500) as $chunk) {
                DB::table('bank_reconciliation_lines')->insert(array_map(static fn ($id): array => ['bank_reconciliation_id' => $rec->id, 'journal_line_id' => $id], $chunk));
            }

            return $this->figures($rec, $bank);
        });
    }

    public function complete(string $reconciliationId): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliationId): BankReconciliation {
            $rec = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliationId);
            $figures = $this->figures($rec, BankAccount::query()->findOrFail($rec->bank_account_id));
            if (! Decimal::isZero($figures['difference'])) {
                throw new \DomainException("The reconciled balance differs from the statement by {$figures['difference']}.");
            }
            $rec->update(['status' => 'completed', 'completed_by' => (string) $this->auth->guard('sanctum')->id(), 'completed_at' => now()]);
            $this->audit->record('banking.reconciliation.completed', 'bank_reconciliation', $rec->id, null, [
                'statement_date' => $rec->statement_date->toDateString(), 'balance' => $rec->statement_balance,
            ]);

            return $rec;
        });
    }

    /** @return array{previously_reconciled: string, ticked: string, difference: string, book_balance: string} */
    private function figures(BankReconciliation $rec, BankAccount $bank): array
    {
        $sum = fn ($q) => (string) ($q->selectRaw('COALESCE(sum(l.debit - l.credit), 0) as s')->value('s') ?? '0');

        $previous = $sum(DB::table('bank_reconciliation_lines as r')->join('bank_reconciliations as b', 'b.id', '=', 'r.bank_reconciliation_id')
            ->join('journal_lines as l', 'l.id', '=', 'r.journal_line_id')->where('b.bank_account_id', $bank->id)->where('b.status', 'completed'));
        $ticked = $sum(DB::table('bank_reconciliation_lines as r')->join('journal_lines as l', 'l.id', '=', 'r.journal_line_id')
            ->where('r.bank_reconciliation_id', $rec->id));
        $book = $sum(DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $bank->account_id)->whereIn('e.status', ['posted', 'reversed'])->where('e.posting_date', '<=', $rec->statement_date->toDateString()));

        $reconciled = Decimal::add($previous, $ticked, 2);

        return [
            'previously_reconciled' => Decimal::round($previous, 2),
            'ticked' => Decimal::round($ticked, 2),
            'book_balance' => Decimal::round($book, 2),
            'difference' => Decimal::sub(Decimal::round((string) $rec->statement_balance, 2), $reconciled, 2),
        ];
    }
}
