<?php

declare(strict_types=1);

namespace Banking\Presentation\Http\Controller;

use Banking\Application\ManageBankAccounts;
use Banking\Application\ManageCheques;
use Banking\Application\ReconcileBank;
use Banking\Infrastructure\Persistence\Eloquent\BankAccount;
use Banking\Infrastructure\Persistence\Eloquent\BankReconciliation;
use Banking\Infrastructure\Persistence\Eloquent\BankTransfer;
use Banking\Infrastructure\Persistence\Eloquent\Cheque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Shared\Infrastructure\Validation\TenantRule;

final class BankingController
{
    private function company(): string
    {
        return (string) Company::query()->orderBy('created_at')->value('id');
    }

    // ── Accounts ─────────────────────────────────────────────────

    /** Bank accounts and cash boxes with their ledger balance and last reconciliation. */
    public function accounts(): JsonResponse
    {
        $balances = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('e.status', ['posted', 'reversed'])
            ->whereIn('l.account_id', BankAccount::query()->select('account_id'))
            ->groupBy('l.account_id')->selectRaw('l.account_id, sum(l.debit - l.credit) as balance')->pluck('balance', 'account_id');
        $reconciled = DB::table('bank_reconciliations')->where('status', 'completed')
            ->groupBy('bank_account_id')->selectRaw('bank_account_id, max(statement_date) as d')->pluck('d', 'bank_account_id');

        $rows = BankAccount::query()->orderByDesc('is_default')->orderBy('kind')->orderBy('name')->get()->map(fn (BankAccount $b): array => $b->toArray() + [
            'balance' => number_format((float) ($balances[$b->account_id] ?? 0), 2, '.', ''),
            'reconciled_to' => $reconciled[$b->id] ?? null,
        ]);

        return new JsonResponse(['data' => $rows]);
    }

    public function storeAccount(Request $request, ManageBankAccounts $action): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:bank,cash'],
            'name' => ['required', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'branch_name' => ['nullable', 'string', 'max:120'],
            'account_no' => ['nullable', 'string', 'max:60'],
            'iban' => ['nullable', 'string', 'max:40'],
            'account_id' => ['nullable', 'uuid', TenantRule::exists('chart_of_accounts')],
        ]);

        return new JsonResponse(['data' => $action->create($this->company(), $data)], 201);
    }

    public function updateAccount(Request $request, string $id): JsonResponse
    {
        $bank = BankAccount::query()->findOrFail($id);
        $bank->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'branch_name' => ['nullable', 'string', 'max:120'],
            'account_no' => ['nullable', 'string', 'max:60'],
            'iban' => ['nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return new JsonResponse(['data' => $bank]);
    }

    // ── Transfers ────────────────────────────────────────────────

    public function transfers(): JsonResponse
    {
        return new JsonResponse(['data' => BankTransfer::query()->orderByDesc('transfer_date')->orderByDesc('created_at')->limit(1000)->get()]);
    }

    public function storeTransfer(Request $request, ManageBankAccounts $action): JsonResponse
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'uuid', TenantRule::exists('bank_accounts')],
            'to_account_id' => ['required', 'uuid', 'different:from_account_id', TenantRule::exists('bank_accounts')],
            'transfer_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        return new JsonResponse(['data' => $action->transfer($data['from_account_id'], $data['to_account_id'], $data['transfer_date'],
            (string) $data['amount'], $data['reference'] ?? null, $request->user()->id)], 201);
    }

    // ── Cheques ──────────────────────────────────────────────────

    public function cheques(Request $request): JsonResponse
    {
        $q = $request->validate(['direction' => ['required', 'in:received,issued']]);

        $rows = Cheque::query()->where('direction', $q['direction'])->orderByRaw("CASE WHEN status IN ('in_hand','deposited','issued') THEN 0 ELSE 1 END")
            ->orderBy('cheque_date')->limit(3000)->get();
        $names = $q['direction'] === 'received'
            ? DB::table('customers')->whereIn('id', $rows->pluck('party_id'))->pluck('name', 'id')
            : DB::table('suppliers')->whereIn('id', $rows->pluck('party_id'))->pluck('name', 'id');

        return new JsonResponse(['data' => $rows->map(fn (Cheque $c): array => $c->toArray() + ['party_name' => $names[$c->party_id] ?? null])]);
    }

    public function storeCheque(Request $request, ManageCheques $action): JsonResponse
    {
        $direction = $request->input('direction');
        $data = $request->validate([
            'direction' => ['required', 'in:received,issued'],
            'party_id' => ['required', 'uuid', TenantRule::exists($direction === 'issued' ? 'suppliers' : 'customers')],
            'cheque_no' => ['required', 'string', 'max:40'],
            'drawer_bank' => ['nullable', 'string', 'max:120'],
            'bank_account_id' => ['nullable', 'uuid', TenantRule::exists('bank_accounts')],
            'cheque_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        return new JsonResponse(['data' => $action->record($this->company(), $data, $request->user()->id)], 201);
    }

    public function chequeAction(Request $request, string $id, string $action, ManageCheques $cheques): JsonResponse
    {
        $cheque = match ($action) {
            'deposit' => $cheques->deposit($id, ...array_values($request->validate([
                'bank_account_id' => ['required', 'uuid', TenantRule::exists('bank_accounts')],
                'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            ]))),
            'clear' => $cheques->clear($id, $request->user()->id),
            'bounce' => $cheques->bounce($id, $request->validate(['reason' => ['required', 'string', 'max:300']])['reason']),
            'cancel' => $cheques->cancel($id, $request->validate(['reason' => ['required', 'string', 'max:300']])['reason']),
            default => abort(404),
        };

        return new JsonResponse(['data' => $cheque]);
    }

    // ── Reconciliation ───────────────────────────────────────────

    public function reconciliations(string $bankAccountId): JsonResponse
    {
        return new JsonResponse(['data' => BankReconciliation::query()->where('bank_account_id', $bankAccountId)->orderByDesc('statement_date')->get()]);
    }

    public function startReconciliation(Request $request, string $bankAccountId, ReconcileBank $action): JsonResponse
    {
        $data = $request->validate([
            'statement_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'statement_balance' => ['required', 'numeric', 'decimal:0,2'],
        ]);
        $rec = $action->start($bankAccountId, $data['statement_date'], (string) $data['statement_balance']);

        return new JsonResponse(['data' => $action->workspace($rec->id)]);
    }

    public function reconciliation(string $id, ReconcileBank $action): JsonResponse
    {
        return new JsonResponse(['data' => $action->workspace($id)]);
    }

    public function tick(Request $request, string $id, ReconcileBank $action): JsonResponse
    {
        $ids = $request->validate(['line_ids' => ['present', 'array'], 'line_ids.*' => ['integer']])['line_ids'];

        return new JsonResponse(['data' => $action->tick($id, array_map('intval', $ids))]);
    }

    public function complete(string $id, ReconcileBank $action): JsonResponse
    {
        return new JsonResponse(['data' => $action->complete($id)]);
    }
}
