<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\ExchangeRates;
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

/**
 * Dr Accounts payable — Cr Cash / Bank.
 *
 * Paying in a foreign currency: the amount is in that currency and goes to
 * the supplier's open bills in the same currency. Payables are relieved at
 * each bill's own rate; the cash leaves at today's rate; the difference is a
 * realised exchange gain or loss.
 */
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
    public function execute(string $supplierId, string $companyId, string $method, string $amount, ?string $reference, string $actorId, ?array $allocations = null, ?string $cashAccountId = null, ?string $currency = null, ?string $fxRate = null): SupplierPayment
    {
        if ($currency !== null && app(ExchangeRates::class)->isForeign($companyId, $currency)) {
            return $this->foreign($supplierId, $companyId, $method, strtoupper($currency), $amount, $fxRate, $reference, $actorId, $allocations, $cashAccountId);
        }

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
            $rest = Decimal::sub($due, $take, 2);
            $update = ['balance_due' => $rest, 'status' => Decimal::isPositive($rest) ? 'open' : 'paid'];
            $foreignTake = null;
            // A foreign bill paid in base currency: its foreign balance falls at the bill's own rate.
            if ($bill->currency !== null && $bill->fx_rate !== null) {
                $foreignTake = Decimal::isPositive($rest) ? ExchangeRates::toForeign($take, (string) $bill->fx_rate) : (string) $bill->foreign_balance_due;
                $foreignRest = Decimal::sub((string) $bill->foreign_balance_due, $foreignTake, 2);
                $update['foreign_balance_due'] = Decimal::isNegative($foreignRest) ? '0' : $foreignRest;
            }
            SupplierPaymentAllocation::query()->create(['supplier_payment_id' => $payment->id, 'supplier_bill_id' => $bill->id, 'amount' => $take, 'foreign_amount' => $foreignTake]);
            $bill->update($update);
            $left = Decimal::sub($left, $take, 2);
        }
    }

    /** @param  array<string, string>|null  $requested  bill id => foreign amount */
    private function foreign(string $supplierId, string $companyId, string $method, string $currency, string $amount, ?string $fxRate, ?string $reference, string $actorId, ?array $requested, ?string $cashAccountId): SupplierPayment
    {
        return DB::transaction(function () use ($supplierId, $companyId, $method, $currency, $amount, $fxRate, $reference, $actorId, $requested, $cashAccountId): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplierId);
            $foreignAmount = Decimal::round($amount, 2);
            $rate = $fxRate ?? app(ExchangeRates::class)->rate($companyId, $currency, now()->toDateString());
            if (! Decimal::isPositive($rate)) {
                throw new \DomainException('The exchange rate must be greater than zero.');
            }
            $cashOut = ExchangeRates::toBase($foreignAmount, $rate);

            $open = SupplierBill::query()->where('supplier_id', $supplier->id)->where('company_id', $companyId)
                ->where('currency', $currency)->where('balance_due', '>', 0)
                ->when($requested !== null, fn ($q) => $q->whereIn('id', array_keys($requested)))
                ->orderBy('due_date')->orderBy('bill_no')->lockForUpdate()->get();
            if ($requested !== null && $open->count() !== count($requested)) {
                throw new \DomainException("One of the selected bills is not an open {$currency} bill of this supplier.");
            }

            $payment = SupplierPayment::query()->create([
                'company_id' => $companyId,
                'supplier_id' => $supplier->id,
                'payment_no' => $this->numbers->next($this->tenant->requireId(), 'supplier_payment', $companyId, 'PAY'),
                'method' => $method,
                'amount' => $cashOut,
                'currency' => $currency,
                'fx_rate' => $rate,
                'foreign_amount' => $foreignAmount,
                'reference' => $reference,
                'created_by' => $actorId,
                'paid_at' => now(),
            ]);

            // Relieve each bill at the rate it was booked at.
            $left = $foreignAmount;
            $relieved = '0';
            foreach ($open as $bill) {
                if (! Decimal::isPositive($left)) {
                    break;
                }
                $foreignDue = (string) $bill->foreign_balance_due;
                $take = $requested !== null ? Decimal::round((string) $requested[$bill->id], 2) : $foreignDue;
                $take = Decimal::cmp($take, $foreignDue) > 0 ? $foreignDue : $take;
                $take = Decimal::cmp($take, $left) > 0 ? $left : $take;
                if (! Decimal::isPositive($take)) {
                    continue;
                }
                $foreignRest = Decimal::sub($foreignDue, $take, 2);
                // The last part of a bill clears exactly what is left in base currency (no rounding crumbs).
                $base = Decimal::isPositive($foreignRest) ? ExchangeRates::toBase($take, (string) $bill->fx_rate) : (string) $bill->balance_due;
                $base = Decimal::cmp($base, (string) $bill->balance_due) > 0 ? (string) $bill->balance_due : $base;
                $baseRest = Decimal::sub((string) $bill->balance_due, $base, 2);

                SupplierPaymentAllocation::query()->create(['supplier_payment_id' => $payment->id, 'supplier_bill_id' => $bill->id, 'amount' => $base, 'foreign_amount' => $take]);
                $bill->update([
                    'balance_due' => $baseRest, 'foreign_balance_due' => $foreignRest,
                    'status' => Decimal::isPositive($foreignRest) && Decimal::isPositive($baseRest) ? 'open' : 'paid',
                ]);
                $relieved = Decimal::add($relieved, $base, 2);
                $left = Decimal::sub($left, $take, 2);
            }

            if (Decimal::isPositive($left)) {
                throw new \DomainException("{$supplier->name} has only ".Decimal::sub($foreignAmount, $left, 2)." {$currency} in open bills; a payment cannot exceed that.");
            }

            $difference = Decimal::sub($cashOut, $relieved, 2); // + paid more in base than booked = loss
            $lines = [
                JournalLineDraft::debit($this->accounts->id($companyId, 'accounts_payable'), $relieved, null, null, 'supplier', $supplier->id),
                JournalLineDraft::credit($cashAccountId ?? $this->accounts->id($companyId, $method === 'cash' ? 'cash' : 'bank'), $cashOut, null, "{$foreignAmount} {$currency} @ {$rate}"),
            ];
            if (Decimal::isPositive($difference)) {
                $lines[] = JournalLineDraft::debit($this->accounts->id($companyId, 'fx_realized'), $difference, null, 'Exchange loss');
            } elseif (Decimal::isNegative($difference)) {
                $lines[] = JournalLineDraft::credit($this->accounts->id($companyId, 'fx_realized'), Decimal::negate($difference, 2), null, 'Exchange gain');
            }

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $companyId,
                postingDate: now()->toDateString(),
                sourceType: 'supplier_payment',
                sourceId: $payment->id,
                description: "Payment {$payment->payment_no} to {$supplier->name} — {$foreignAmount} {$currency}",
                lines: $lines,
            ));

            $payment->update(['journal_entry_id' => $journal->id, 'fx_difference' => $difference]);
            $supplier->forceFill(['balance' => Decimal::sub((string) $supplier->balance, $relieved)])->save();

            $this->audit->record('purchasing.supplier.paid', 'supplier', $supplier->id, null, [
                'payment_no' => $payment->payment_no, 'amount' => $cashOut, 'currency' => $currency, 'foreign_amount' => $foreignAmount, 'rate' => $rate, 'fx_difference' => $difference,
            ]);

            return $payment->refresh();
        });
    }
}
