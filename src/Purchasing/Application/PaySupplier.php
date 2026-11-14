<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierBill;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierPayment;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierPaymentAllocation;
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

    /** @param  array<string, string>|null  $allocations  bill id => amount; null = oldest due first */
    public function execute(string $supplierId, string $companyId, string $method, string $amount, ?string $reference, string $actorId, ?array $allocations = null, ?string $cashAccountId = null): SupplierPayment
    {
        return DB::transaction(function () use ($supplierId, $companyId, $method, $amount, $reference, $actorId, $allocations, $cashAccountId): SupplierPayment {
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
                    JournalLineDraft::credit($cashAccountId ?? $this->accounts->id($companyId, $method === 'cash' ? 'cash' : 'bank'), $amount),
                ],
            ));

            $payment->update(['journal_entry_id' => $journal->id]);
            $supplier->forceFill(['balance' => Decimal::sub((string) $supplier->balance, $amount)])->save();
            $this->allocate($payment, $amount, $allocations);

            $this->audit->record('purchasing.supplier.paid', 'supplier', $supplier->id, null, ['payment_no' => $payment->payment_no, 'amount' => $amount, 'method' => $method]);

            return $payment;
        });
    }

    /**
     * Applies the payment to open bills: the ones named, or the oldest due
     * first. What is left reduces receipts not yet billed.
     *
     * @param  array<string, string>|null  $requested
     */
    private function allocate(SupplierPayment $payment, string $amount, ?array $requested): void
    {
        $open = SupplierBill::query()->where('supplier_id', $payment->supplier_id)->where('company_id', $payment->company_id)->where('balance_due', '>', 0)
            ->when($requested !== null, fn ($q) => $q->whereIn('id', array_keys($requested)))
            ->orderBy('due_date')->orderBy('bill_no')->lockForUpdate()->get();

        if ($requested !== null && $open->count() !== count($requested)) {
            throw new \DomainException('One of the selected bills is not open for this supplier.');
        }

        $left = $amount;
        foreach ($open as $bill) {
            if (! Decimal::isPositive($left)) {
                break;
            }
            $due = (string) $bill->balance_due;
            $take = $requested !== null ? Decimal::round((string) $requested[$bill->id], 2) : $due;
            $take = Decimal::cmp($take, $due) > 0 ? $due : $take;
            $take = Decimal::cmp($take, $left) > 0 ? $left : $take;
            if (! Decimal::isPositive($take)) {
                continue;
            }
            SupplierPaymentAllocation::query()->create(['supplier_payment_id' => $payment->id, 'supplier_bill_id' => $bill->id, 'amount' => $take]);
            $rest = Decimal::sub($due, $take, 2);
            $bill->update(['balance_due' => $rest, 'status' => Decimal::isPositive($rest) ? 'open' : 'paid']);
            $left = Decimal::sub($left, $take, 2);
        }
    }
}
