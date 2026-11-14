<?php

declare(strict_types=1);

namespace Banking\Application;

use Banking\Infrastructure\Persistence\Eloquent\BankAccount;
use Banking\Infrastructure\Persistence\Eloquent\BankTransfer;
use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostJournalEntry;
use Finance\Infrastructure\Persistence\Eloquent\Account;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/** Bank accounts and cash boxes, each with its own ledger account, and transfers between them. */
final class ManageBankAccounts
{
    public function __construct(
        private readonly PostJournalEntry $postJournal,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(string $companyId, array $data): BankAccount
    {
        return DB::transaction(function () use ($companyId, $data): BankAccount {
            // A new ledger account under cash (101x) or bank (102x), unless an existing one is linked.
            $accountId = $data['account_id'] ?? null;
            if ($accountId === null) {
                $base = $data['kind'] === 'cash' ? 1011 : 1021;
                $code = $base;
                while (Account::query()->where('company_id', $companyId)->where('code', (string) $code)->exists()) {
                    $code++;
                }
                $accountId = Account::query()->create([
                    'company_id' => $companyId, 'code' => (string) $code, 'name' => $data['name'],
                    'account_type' => 'asset', 'is_control_account' => false, 'is_system' => false, 'status' => 'active',
                ])->id;
            } elseif (Account::query()->where('company_id', $companyId)->whereKey($accountId)->value('account_type') !== 'asset') {
                throw new \DomainException('A bank or cash account must be linked to an asset ledger account.');
            }

            $bank = BankAccount::query()->create([
                'company_id' => $companyId, 'account_id' => $accountId, 'kind' => $data['kind'], 'name' => $data['name'],
                'bank_name' => $data['bank_name'] ?? null, 'branch_name' => $data['branch_name'] ?? null,
                'account_no' => $data['account_no'] ?? null, 'iban' => $data['iban'] ?? null, 'is_default' => false, 'status' => 'active',
            ]);
            $this->audit->record('banking.account.created', 'bank_account', $bank->id, null, ['name' => $bank->name]);

            return $bank;
        });
    }

    /** Cash deposited in the bank, withdrawn, or moved between bank accounts. */
    public function transfer(string $fromId, string $toId, string $date, string $amount, ?string $reference, string $actorId): BankTransfer
    {
        if ($fromId === $toId) {
            throw new \DomainException('Choose two different accounts.');
        }

        return DB::transaction(function () use ($fromId, $toId, $date, $amount, $reference, $actorId): BankTransfer {
            $from = BankAccount::query()->where('status', 'active')->findOrFail($fromId);
            $to = BankAccount::query()->where('status', 'active')->where('company_id', $from->company_id)->findOrFail($toId);
            $amount = Decimal::round($amount, 2);

            $transfer = BankTransfer::query()->create([
                'company_id' => $from->company_id,
                'transfer_no' => $this->numbers->next($this->tenant->requireId(), 'bank_transfer', $from->company_id, 'TRF'),
                'from_account_id' => $from->id, 'to_account_id' => $to->id, 'transfer_date' => $date, 'amount' => $amount,
                'reference' => $reference, 'created_by' => $actorId,
            ]);

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $from->company_id, postingDate: $date, sourceType: 'bank_transfer', sourceId: $transfer->id,
                description: "{$transfer->transfer_no}: {$from->name} → {$to->name}".($reference ? " ({$reference})" : ''),
                lines: [
                    JournalLineDraft::debit($to->account_id, $amount, null, $reference),
                    JournalLineDraft::credit($from->account_id, $amount, null, $reference),
                ],
            ));
            $transfer->update(['journal_entry_id' => $journal->id]);
            $this->audit->record('banking.transfer.posted', 'bank_transfer', $transfer->id, null, ['amount' => $amount, 'from' => $from->name, 'to' => $to->name]);

            return $transfer;
        });
    }
}
