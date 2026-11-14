<?php

declare(strict_types=1);

namespace Sales\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Inventory\Domain\Enum\MovementType;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Domain\StockPosting;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Sales\Domain\Enum\PaymentMethod;
use Sales\Domain\Exception\CreditLimitExceeded;
use Sales\Domain\Exception\ShiftNotOpen;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Sales\Infrastructure\Persistence\Eloquent\PosShift;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Sales\Infrastructure\Persistence\Eloquent\SaleItem;
use Sales\Infrastructure\Persistence\Eloquent\SaleTender;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\EventOutbox;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * WF-SALE: one database transaction writes the sale, its tenders, the stock
 * issue and the journal. Either all of it exists or none of it does.
 *
 *   Dr Cash / Bank / Receivable      Cr Sales revenue, Sales tax payable
 *   Dr Cost of goods sold            Cr Inventory
 */
final class RecordSale
{
    public function __construct(
        private readonly PriceCart $cart,
        private readonly StockLedger $stock,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: string|int|float, unit_price?: string|int|float|null, discount?: string|int|float|null}>  $lines
     * @param  list<array{method: string, amount: string|int|float, reference?: string|null}>  $tenders
     */
    public function execute(
        string $cashierId,
        array $lines,
        array $tenders,
        ?string $customerId,
        bool $mayOverridePrice,
        ?string $idempotencyKey,
    ): Sale {
        return DB::transaction(function () use ($cashierId, $lines, $tenders, $customerId, $mayOverridePrice, $idempotencyKey): Sale {
            // Locking the shift serialises this cashier's sales with closing the shift.
            $shift = PosShift::query()->where('cashier_id', $cashierId)->where('status', 'open')->lockForUpdate()->first()
                ?? throw ShiftNotOpen::forCashier();
            $terminal = $shift->terminal;
            $company = Company::query()->findOrFail($shift->company_id);

            $priced = $this->cart->price($lines, $mayOverridePrice);
            $grandTotal = $priced['grand_total'];

            if (! Decimal::isPositive($grandTotal)) {
                throw new \DomainException('The sale total must be greater than zero.');
            }

            [$tenderRows, $paid, $change, $onCredit] = $this->settle($tenders, $grandTotal);
            $customer = $this->chargeCustomer($customerId, $onCredit);

            $sale = Sale::query()->create([
                'company_id' => $company->id,
                'branch_id' => $shift->branch_id,
                'warehouse_id' => $terminal->warehouse_id,
                'terminal_id' => $terminal->id,
                'shift_id' => $shift->id,
                'cashier_id' => $cashierId,
                'customer_id' => $customer?->id,
                'sale_no' => $this->numbers->next($this->tenant->requireId(), 'sale', $shift->branch_id, 'INV'),
                'status' => Sale::COMPLETED,
                'currency' => $company->base_currency,
                'subtotal' => $priced['subtotal'],
                'discount_total' => $priced['discount_total'],
                'tax_total' => $priced['tax_total'],
                'grand_total' => $grandTotal,
                'paid_total' => $paid,
                'change_total' => $change,
                'cost_total' => '0',
                'idempotency_key' => $idempotencyKey,
                'sold_at' => now(),
            ]);

            $costTotal = '0';

            foreach ($priced['lines'] as $line) {
                $product = $line['product'];
                $unitCost = '0';

                if ($product->product_type->tracksInventory()) {
                    $unitCost = $this->stock->issue(new StockPosting(
                        $company->id, $terminal->warehouse_id, $product->id, $line['quantity'],
                        MovementType::Sale, 'sale', $sale->id,
                    ));
                    $costTotal = Decimal::add($costTotal, Decimal::mul($line['quantity'], $unitCost, 2));
                }

                SaleItem::query()->create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax'],
                    'net_amount' => $line['net'],
                    'line_total' => $line['total'],
                    'cost_snapshot' => $unitCost,
                ]);
            }

            foreach ($tenderRows as $row) {
                SaleTender::query()->create(['sale_id' => $sale->id] + $row);
            }

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $company->id,
                postingDate: now()->toDateString(),
                sourceType: 'sale',
                sourceId: $sale->id,
                description: "POS sale {$sale->sale_no}",
                lines: $this->journalLines($sale, $tenderRows, $change, $priced['net_total'], $priced['tax_total'], $costTotal, $customer),
            ));

            $sale->update(['cost_total' => $costTotal, 'journal_entry_id' => $journal->id]);

            $this->outbox->record('sales.sale.completed', 'sale', $sale->id, [
                'sale_no' => $sale->sale_no,
                'branch_id' => $sale->branch_id,
                'grand_total' => $grandTotal,
                'currency' => $sale->currency,
            ]);

            return $sale->load(['items', 'tenders', 'customer:id,name,customer_code']);
        });
    }

    /**
     * Validate the tenders against the total.
     *
     * @param  list<array{method: string, amount: string|int|float, reference?: string|null}>  $tenders
     * @return array{0: list<array{method: string, amount: string, external_reference: string|null}>, 1: string, 2: string, 3: string}
     *                                                                                                                              rows, paid, change, amount on credit
     */
    private function settle(array $tenders, string $grandTotal): array
    {
        $rows = [];
        $paid = '0';
        $cash = '0';
        $onCredit = '0';

        foreach ($tenders as $tender) {
            $method = PaymentMethod::from($tender['method']);
            $amount = Decimal::round((string) $tender['amount'], 2);

            if (! Decimal::isPositive($amount)) {
                throw new \DomainException('Tender amounts must be greater than zero.');
            }

            $rows[] = ['method' => $method->value, 'amount' => $amount, 'external_reference' => $tender['reference'] ?? null];
            $paid = Decimal::add($paid, $amount);

            if ($method === PaymentMethod::Cash) {
                $cash = Decimal::add($cash, $amount);
            }
            if ($method === PaymentMethod::Credit) {
                $onCredit = Decimal::add($onCredit, $amount);
            }
        }

        if (Decimal::cmp($paid, $grandTotal) < 0) {
            throw new \DomainException('Payment is short by '.Decimal::sub($grandTotal, $paid, 2).'.');
        }

        // Change is handed back in cash, so it can never exceed the cash tendered.
        $change = Decimal::sub($paid, $grandTotal);
        if (Decimal::cmp($change, $cash) > 0) {
            throw new \DomainException('Non-cash payments exceed the sale total.');
        }

        return [$rows, $paid, $change, $onCredit];
    }

    private function chargeCustomer(?string $customerId, string $onCredit): ?Customer
    {
        if ($customerId === null) {
            if (Decimal::isPositive($onCredit)) {
                throw new \DomainException('Select a customer to sell on credit.');
            }

            return null;
        }

        $customer = Customer::query()->where('status', 'active')->lockForUpdate()->findOrFail($customerId);

        if (Decimal::isPositive($onCredit)) {
            $newBalance = Decimal::add((string) $customer->balance, $onCredit);

            if (Decimal::cmp($newBalance, (string) $customer->credit_limit) > 0) {
                throw CreditLimitExceeded::forCustomer($customer->name, (string) $customer->credit_limit, (string) $customer->balance, $onCredit);
            }

            $customer->forceFill(['balance' => $newBalance])->save();
        }

        return $customer;
    }

    /**
     * @param  list<array{method: string, amount: string, external_reference: string|null}>  $tenders
     * @return list<JournalLineDraft>
     */
    private function journalLines(Sale $sale, array $tenders, string $change, string $net, string $tax, string $cost, ?Customer $customer): array
    {
        $company = $sale->company_id;
        $branch = $sale->branch_id;

        // Money in, grouped by the account each tender lands in. Change leaves the cash drawer.
        $received = [];
        foreach ($tenders as $tender) {
            $key = PaymentMethod::from($tender['method'])->postingAccount();
            $received[$key] = Decimal::add($received[$key] ?? '0', $tender['amount']);
        }
        if (Decimal::isPositive($change)) {
            $received['cash'] = Decimal::sub($received['cash'], $change);
        }

        $lines = [];
        foreach ($received as $key => $amount) {
            $isReceivable = $key === 'accounts_receivable';
            $lines[] = JournalLineDraft::debit(
                $this->accounts->id($company, $key), $amount, $branch, null,
                $isReceivable ? 'customer' : null, $isReceivable ? $customer?->id : null,
            );
        }

        $lines[] = JournalLineDraft::credit($this->accounts->id($company, 'sales_revenue'), $net, $branch);
        $lines[] = JournalLineDraft::credit($this->accounts->id($company, 'sales_tax_payable'), $tax, $branch);
        $lines[] = JournalLineDraft::debit($this->accounts->id($company, 'cost_of_goods_sold'), $cost, $branch);
        $lines[] = JournalLineDraft::credit($this->accounts->id($company, 'inventory'), $cost, $branch);

        return $lines;
    }
}
