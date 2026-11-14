<?php

declare(strict_types=1);

namespace Sales\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Sales\Infrastructure\Persistence\Eloquent\CustomerPayment;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Money received against a customer's account balance.
 *   Dr Cash / Bank     Cr Accounts receivable
 *
 * This is a back-office receipt: cash taken here is not part of a POS
 * shift's drawer count.
 */
final class ReceiveCustomerPayment
{
    public function __construct(
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function execute(string $customerId, string $companyId, ?string $branchId, string $method, string $amount, ?string $reference, string $actorId): CustomerPayment
    {
        return DB::transaction(function () use ($customerId, $companyId, $branchId, $method, $amount, $reference, $actorId): CustomerPayment {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $amount = Decimal::round($amount, 2);

            if (Decimal::cmp($amount, (string) $customer->balance) > 0) {
                throw new \DomainException("{$customer->name} owes ".Decimal::round((string) $customer->balance, 2).'; a payment cannot exceed that.');
            }

            $payment = CustomerPayment::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'receipt_no' => $this->numbers->next($this->tenant->requireId(), 'customer_payment', $companyId, 'RCV'),
                'method' => $method,
                'amount' => $amount,
                'reference' => $reference,
                'created_by' => $actorId,
                'received_at' => now(),
            ]);

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $companyId,
                postingDate: now()->toDateString(),
                sourceType: 'customer_payment',
                sourceId: $payment->id,
                description: "Receipt {$payment->receipt_no} from {$customer->name}",
                lines: [
                    JournalLineDraft::debit($this->accounts->id($companyId, $method === 'cash' ? 'cash' : 'bank'), $amount, $branchId),
                    JournalLineDraft::credit($this->accounts->id($companyId, 'accounts_receivable'), $amount, $branchId, null, 'customer', $customer->id),
                ],
            ));

            $payment->update(['journal_entry_id' => $journal->id]);
            $customer->forceFill(['balance' => Decimal::sub((string) $customer->balance, $amount)])->save();

            $this->audit->record('sales.customer.payment_received', 'customer', $customer->id, null,
                ['receipt_no' => $payment->receipt_no, 'amount' => $amount, 'method' => $method]);

            return $payment;
        });
    }
}
