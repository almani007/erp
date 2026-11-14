<?php

declare(strict_types=1);

namespace Sales\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
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

    /**
     * @param  array<string, string>|null  $allocations  sale id => amount; null = oldest invoices first
     */
    public function execute(string $customerId, string $companyId, ?string $branchId, string $method, string $amount, ?string $reference, string $actorId, ?array $allocations = null, ?string $cashAccountId = null): CustomerPayment
    {
        return DB::transaction(function () use ($customerId, $companyId, $branchId, $method, $amount, $reference, $actorId, $allocations, $cashAccountId): CustomerPayment {
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
                    // A specific bank account (Banking) or the default cash / bank account.
                    JournalLineDraft::debit($cashAccountId ?? $this->accounts->id($companyId, $method === 'cash' ? 'cash' : 'bank'), $amount, $branchId),
                    JournalLineDraft::credit($this->accounts->id($companyId, 'accounts_receivable'), $amount, $branchId, null, 'customer', $customer->id),
                ],
            ));

            $payment->update(['journal_entry_id' => $journal->id]);
            $customer->forceFill(['balance' => Decimal::sub((string) $customer->balance, $amount)])->save();
            $this->allocate($payment, $amount, $allocations);

            $this->audit->record('sales.customer.payment_received', 'customer', $customer->id, null,
                ['receipt_no' => $payment->receipt_no, 'amount' => $amount, 'method' => $method]);

            return $payment->load('allocations');
        });
    }

    /**
     * Applies the receipt to the customer's open invoices: the ones named,
     * or the oldest due first. What is left is unapplied credit on the account.
     *
     * @param  array<string, string>|null  $requested
     */
    private function allocate(CustomerPayment $payment, string $amount, ?array $requested): void
    {
        $open = Sale::query()->where('customer_id', $payment->customer_id)->where('status', Sale::COMPLETED)
            ->where('balance_due', '>', 0)
            ->when($requested !== null, fn ($q) => $q->whereIn('id', array_keys($requested)))
            ->orderByRaw('COALESCE(due_date, sold_at::date)')->orderBy('sold_at')
            ->lockForUpdate()->get();

        if ($requested !== null && $open->count() !== count($requested)) {
            throw new \DomainException('One of the selected invoices is not open for this customer.');
        }

        $left = $amount;
        foreach ($open as $sale) {
            if (! Decimal::isPositive($left)) {
                break;
            }
            $due = (string) $sale->balance_due;
            $want = $requested !== null ? Decimal::round((string) $requested[$sale->id], 2) : $due;
            $take = self::smaller(self::smaller($want, $due), $left);
            if (! Decimal::isPositive($take)) {
                continue;
            }

            DB::table('customer_payment_allocations')->insert([
                'id' => (string) Str::uuid7(), 'customer_payment_id' => $payment->id, 'sale_id' => $sale->id, 'amount' => $take,
            ]);
            $sale->update(['balance_due' => Decimal::sub($due, $take)]);
            $left = Decimal::sub($left, $take);
        }
    }

    private static function smaller(string $a, string $b): string
    {
        return Decimal::cmp($a, $b) <= 0 ? $a : $b;
    }
}
