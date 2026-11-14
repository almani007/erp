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
use Sales\Domain\Exception\ShiftNotOpen;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Sales\Infrastructure\Persistence\Eloquent\PosShift;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Sales\Infrastructure\Persistence\Eloquent\SalesReturn;
use Sales\Infrastructure\Persistence\Eloquent\SalesReturnItem;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\EventOutbox;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * WF-RETURN: a return is its own document linked to the original sale,
 * which is never edited.
 *
 *   Dr Sales revenue, Sales tax payable     Cr Cash / Bank / Receivable
 *   Dr Inventory                            Cr Cost of goods sold   (restocked lines only)
 *
 * Amounts are the returned share of what the customer actually paid on
 * each line, so discounts and tax are refunded in proportion.
 */
final class RecordSaleReturn
{
    public const array REFUND_METHODS = ['cash', 'bank', 'account'];

    public function __construct(
        private readonly StockLedger $stock,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * @param  list<array{sale_item_id: string, quantity: string|int|float, restock?: bool}>  $lines
     */
    public function execute(string $saleId, array $lines, string $refundMethod, string $reason, string $actorId): SalesReturn
    {
        return DB::transaction(function () use ($saleId, $lines, $refundMethod, $reason, $actorId): SalesReturn {
            // The lock stops two returns against the same sale from both passing the quantity check.
            $sale = Sale::query()->lockForUpdate()->findOrFail($saleId);

            if ($sale->status !== Sale::COMPLETED) {
                throw new \DomainException("Sale {$sale->sale_no} is voided and cannot be returned.");
            }

            $shiftId = $refundMethod === 'cash' ? $this->cashierShift($actorId, $sale)->id : null;

            $return = SalesReturn::query()->create([
                'company_id' => $sale->company_id,
                'branch_id' => $sale->branch_id,
                'warehouse_id' => $sale->warehouse_id,
                'sale_id' => $sale->id,
                'shift_id' => $shiftId,
                'return_no' => $this->numbers->next($this->tenant->requireId(), 'sales_return', $sale->branch_id, 'RET'),
                'reason' => $reason,
                'refund_method' => $refundMethod,
                'net_total' => '0', 'tax_total' => '0', 'grand_total' => '0', 'cost_total' => '0',
                'created_by' => $actorId,
                'returned_at' => now(),
            ]);

            $items = $sale->items->keyBy('id');
            $alreadyReturned = DB::table('sales_return_items as ri')
                ->join('sales_returns as r', 'r.id', '=', 'ri.sales_return_id')
                ->where('r.sale_id', $sale->id)
                ->groupBy('ri.sale_item_id')
                ->selectRaw('ri.sale_item_id, SUM(ri.quantity) AS qty, SUM(ri.net_amount) AS net, SUM(ri.tax_amount) AS tax')
                ->get()
                ->keyBy('sale_item_id');

            $net = $tax = $total = $cost = '0';

            foreach ($lines as $line) {
                $item = $items->get($line['sale_item_id'])
                    ?? throw new \DomainException('A returned line does not belong to this sale.');

                $qty = (string) $line['quantity'];
                $prior = $alreadyReturned->get($item->id);
                $eligible = Decimal::sub((string) $item->quantity, (string) ($prior->qty ?? '0'), Decimal::QTY_SCALE);

                if (Decimal::cmp($qty, $eligible) > 0) {
                    throw new \DomainException("Only {$this->trim($eligible)} of {$item->product_name} can still be returned.");
                }

                if (Decimal::cmp($qty, $eligible) === 0) {
                    // Returning all that is left refunds exactly the remainder, so
                    // several partial returns can never add up to more than was paid.
                    $lineNet = Decimal::sub((string) $item->net_amount, (string) ($prior->net ?? '0'), 2);
                    $lineTax = Decimal::sub((string) $item->tax_amount, (string) ($prior->tax ?? '0'), 2);
                } else {
                    $share = Decimal::div($qty, (string) $item->quantity, 10);
                    $lineNet = Decimal::mul((string) $item->net_amount, $share, 2);
                    $lineTax = Decimal::mul((string) $item->tax_amount, $share, 2);
                }
                $lineTotal = Decimal::add($lineNet, $lineTax);

                $restock = (bool) ($line['restock'] ?? true);
                $tracked = DB::table('stock_movements')
                    ->where('source_type', 'sale')->where('source_id', $sale->id)->where('product_id', $item->product_id)->exists();

                if ($restock && $tracked) {
                    // The line may have been sold in packs; stock and cost are kept in base units.
                    $baseQty = Decimal::mul($qty, (string) $item->unit_factor, Decimal::QTY_SCALE);
                    $this->stock->receive(new StockPosting(
                        $sale->company_id, $sale->warehouse_id, $item->product_id, $baseQty,
                        MovementType::SaleReturn, 'sales_return', $return->id,
                    ), (string) $item->cost_snapshot);
                    $cost = Decimal::add($cost, Decimal::mul($baseQty, (string) $item->cost_snapshot, 2));
                }

                SalesReturnItem::query()->create([
                    'sales_return_id' => $return->id,
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'net_amount' => $lineNet,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal,
                    'unit_cost' => $item->cost_snapshot,
                    'restocked' => $restock && $tracked,
                ]);

                $net = Decimal::add($net, $lineNet);
                $tax = Decimal::add($tax, $lineTax);
                $total = Decimal::add($total, $lineTotal);
            }

            $customer = $this->creditCustomer($sale, $refundMethod, $total);

            $refundAccount = match ($refundMethod) {
                'cash' => 'cash',
                'bank' => 'bank',
                default => 'accounts_receivable',
            };

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $sale->company_id,
                postingDate: now()->toDateString(),
                sourceType: 'sales_return',
                sourceId: $return->id,
                description: "Return {$return->return_no} against sale {$sale->sale_no}",
                lines: [
                    JournalLineDraft::debit($this->accounts->id($sale->company_id, 'sales_revenue'), $net, $sale->branch_id),
                    JournalLineDraft::debit($this->accounts->id($sale->company_id, 'sales_tax_payable'), $tax, $sale->branch_id),
                    JournalLineDraft::credit(
                        $this->accounts->id($sale->company_id, $refundAccount), $total, $sale->branch_id, null,
                        $customer !== null ? 'customer' : null, $customer?->id,
                    ),
                    JournalLineDraft::debit($this->accounts->id($sale->company_id, 'inventory'), $cost, $sale->branch_id),
                    JournalLineDraft::credit($this->accounts->id($sale->company_id, 'cost_of_goods_sold'), $cost, $sale->branch_id),
                ],
            ));

            $return->update([
                'net_total' => $net, 'tax_total' => $tax, 'grand_total' => $total, 'cost_total' => $cost,
                'journal_entry_id' => $journal->id,
            ]);

            $this->audit->record('sales.return.recorded', 'sales_return', $return->id, null, [
                'return_no' => $return->return_no, 'sale_no' => $sale->sale_no, 'grand_total' => $total, 'refund_method' => $refundMethod,
            ], $reason);
            $this->outbox->record('sales.sale.returned', 'sales_return', $return->id, ['return_no' => $return->return_no, 'sale_id' => $sale->id]);

            return $return->load('items');
        });
    }

    /** A cash refund comes out of the drawer the cashier is working on, in the sale's branch. */
    private function cashierShift(string $actorId, Sale $sale): PosShift
    {
        $shift = PosShift::query()->where('cashier_id', $actorId)->where('status', 'open')->lockForUpdate()->first()
            ?? throw ShiftNotOpen::forCashier();

        if ($shift->branch_id !== $sale->branch_id) {
            throw new \DomainException('Cash refunds must be paid from a shift in the branch where the sale was made.');
        }

        return $shift;
    }

    private function creditCustomer(Sale $sale, string $refundMethod, string $total): ?Customer
    {
        if ($refundMethod !== 'account') {
            return null;
        }

        if ($sale->customer_id === null) {
            throw new \DomainException('This sale has no customer, so it cannot be refunded to an account.');
        }

        $customer = Customer::query()->lockForUpdate()->findOrFail($sale->customer_id);
        $customer->forceFill(['balance' => Decimal::sub((string) $customer->balance, $total)])->save();

        return $customer;
    }

    private function trim(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
