<?php

declare(strict_types=1);

namespace Finance\Presentation\Http\Controller;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Finance\Application\ReverseJournalEntry;
use Finance\Infrastructure\Persistence\Eloquent\Account;
use Finance\Infrastructure\Persistence\Eloquent\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\AuditTrail;
use Shared\Infrastructure\Validation\TenantRule;

final class JournalController
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'source_type' => ['nullable', 'string', 'max:80'],
        ]);

        $entries = JournalEntry::query()
            ->where('company_id', $q['company_id'])
            ->when($q['from'] ?? null, fn ($b, $d) => $b->whereDate('posting_date', '>=', $d))
            ->when($q['to'] ?? null, fn ($b, $d) => $b->whereDate('posting_date', '<=', $d))
            ->when($q['source_type'] ?? null, fn ($b, $s) => $b->where('source_type', $s))
            ->withSum('lines as total', 'debit')
            ->orderByDesc('posting_date')->orderByDesc('journal_no')
            ->limit(500)
            ->get();

        return new JsonResponse(['data' => $entries]);
    }

    public function show(string $journalId): JsonResponse
    {
        return new JsonResponse(['data' => JournalEntry::with('lines.account:id,code,name')->findOrFail($journalId)]);
    }

    /** Manual journal voucher. */
    public function store(Request $request, PostJournalEntry $post, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = [];
        foreach ($data['lines'] as $i => $line) {
            $debit = (string) ($line['debit'] ?? '0');
            $credit = (string) ($line['credit'] ?? '0');

            if (bccomp($debit, '0', 4) > 0 && bccomp($credit, '0', 4) > 0) {
                throw new \DomainException('Line '.($i + 1).' has both a debit and a credit; use one side per line.');
            }

            $lines[] = bccomp($debit, '0', 4) > 0
                ? JournalLineDraft::debit($line['account_id'], $debit, $line['branch_id'] ?? null, $line['description'] ?? null)
                : JournalLineDraft::credit($line['account_id'], $credit, $line['branch_id'] ?? null, $line['description'] ?? null);
        }

        $entry = DB::transaction(function () use ($data, $lines, $post, $audit): JournalEntry {
            $entry = $post->execute(new JournalDraft(
                companyId: $data['company_id'],
                postingDate: $data['posting_date'],
                sourceType: 'manual',
                sourceId: null,
                description: $data['description'],
                lines: $lines,
            ));

            $audit->record('finance.journal.posted', 'journal_entry', $entry->id, null, ['journal_no' => $entry->journal_no]);

            return $entry;
        });

        return new JsonResponse(['data' => $entry->load('lines.account:id,code,name')], 201);
    }

    /**
     * Expense or income voucher: a guided two-line journal for everyday
     * money out (rent, utilities, petty cash) and money in (other income).
     *
     *   expense:  Dr the expense account     Cr Cash / Bank
     *   income:   Dr Cash / Bank             Cr the income account
     */
    public function voucher(Request $request, PostJournalEntry $post, PostingAccounts $accounts, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'type' => ['required', 'in:expense,income'],
            'account_id' => ['required', 'uuid'],
            'method' => ['required', 'in:cash,bank'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'party' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:300'],
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
        ]);

        $account = Account::query()->where('company_id', $data['company_id'])->findOrFail($data['account_id']);
        $expected = $data['type'] === 'expense' ? ['expense', 'contra_revenue'] : ['revenue', 'contra_expense'];

        if (! in_array($account->account_type->value, $expected, true)) {
            throw new \DomainException("{$account->name} is not ".($data['type'] === 'expense' ? 'an expense' : 'an income').' account.');
        }

        $money = $accounts->id($data['company_id'], $data['method']);
        $amount = (string) $data['amount'];
        $branch = $data['branch_id'] ?? null;
        $note = trim(($data['party'] ?? '').' — '.$data['description'], ' —');

        $entry = DB::transaction(function () use ($data, $account, $money, $amount, $branch, $note, $post, $audit): JournalEntry {
            $entry = $post->execute(new JournalDraft(
                companyId: $data['company_id'],
                postingDate: $data['posting_date'],
                sourceType: $data['type'].'_voucher',
                sourceId: null,
                description: $note,
                lines: $data['type'] === 'expense'
                    ? [JournalLineDraft::debit($account->id, $amount, $branch), JournalLineDraft::credit($money, $amount, $branch)]
                    : [JournalLineDraft::debit($money, $amount, $branch), JournalLineDraft::credit($account->id, $amount, $branch)],
            ));

            $audit->record('finance.voucher.posted', 'journal_entry', $entry->id, null, ['journal_no' => $entry->journal_no, 'type' => $data['type'], 'amount' => $amount]);

            return $entry;
        });

        return new JsonResponse(['data' => $entry->load('lines.account:id,code,name')], 201);
    }

    public function reverse(Request $request, string $journalId, ReverseJournalEntry $reverse, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
            'reversal_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Entries created by a business document are corrected through that
        // document (void the sale, etc.) so stock and ledger stay in step.
        $original = JournalEntry::findOrFail($journalId);
        if (! in_array($original->source_type, ['manual', 'expense_voucher', 'income_voucher'], true)) {
            throw new \DomainException("This entry was generated by a {$original->source_type} document; reverse that document instead.");
        }

        $reversal = DB::transaction(function () use ($original, $data, $reverse, $audit): JournalEntry {
            $reversal = $reverse->execute($original->id, $data['reason'], $data['reversal_date'] ?? now()->toDateString());

            $audit->record('finance.journal.reversed', 'journal_entry', $original->id, null,
                ['reversal_journal_no' => $reversal->journal_no], $data['reason']);

            return $reversal;
        });

        return new JsonResponse(['data' => $reversal->load('lines.account:id,code,name')], 201);
    }
}
