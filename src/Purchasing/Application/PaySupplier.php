<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierPayment;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/** Dr Accounts payable — Cr Cash / Bank. */
final class PaySupplier
{
    public function __construct(
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function execute(string $supplierId, string $companyId, string $method, string $amount, ?string $reference, string $actorId): SupplierPayment
    {
        return DB::transaction(function () use ($supplierId, $companyId, $method, $amount, $reference, $actorId): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplierId);
            $amount = Decimal::round($amount, 2);

            if (Decimal::cmp($amount, (string) $supplier->balance) > 0) {
                throw new \DomainException("You owe {$supplier->name} ".Decimal::round((string) $supplier->balance, 2).'; a payment cannot exceed that. Advances are not supported yet.');
            }

            $payment = SupplierPayment::query()->create([
                'company_id' => $companyId,
                'supplier_id' => $supplier->id,
                'payment_no' => $this->numbers->next($this->tenant->requireId(), 'supplier_payment', $companyId, 'PAY'),
                'method' => $method,
                'amount' => $amount,
                'reference' => $reference,
                'created_by' => $actorId,
                'paid_at' => now(),
            ]);

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $companyId,
                postingDate: now()->toDateString(),
                sourceType: 'supplier_payment',
                sourceId: $payment->id,
                description: "Payment {$payment->payment_no} to {$supplier->name}",
                lines: [
                    JournalLineDraft::debit($this->accounts->id($companyId, 'accounts_payable'), $amount, null, null, 'supplier', $supplier->id),
                    JournalLineDraft::credit($this->accounts->id($companyId, $method === 'cash' ? 'cash' : 'bank'), $amount),
                ],
            ));

            $payment->update(['journal_entry_id' => $journal->id]);
            $supplier->forceFill(['balance' => Decimal::sub((string) $supplier->balance, $amount)])->save();

            $this->audit->record('purchasing.supplier.paid', 'supplier', $supplier->id, null, ['payment_no' => $payment->payment_no, 'amount' => $amount, 'method' => $method]);

            return $payment;
        });
    }
}
