<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Inventory\Domain\Enum\MovementType;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Domain\StockPosting;
use Purchasing\Infrastructure\Persistence\Eloquent\GoodsReceipt;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseReturn;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierBill;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Debit note: goods sent back from a receipt (damaged, expired, wrong).
 * The supplier is owed less at the receipt price; stock leaves at its
 * current average cost, and the gap goes to purchase price variance.
 *
 *   Dr Accounts payable   Cr Inventory (average cost), Input tax, ± Price variance
 */
final class RecordPurchaseReturn
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
    ) {}

    /** @param  list<array{goods_receipt_item_id: string, quantity: string}>  $lines */
    public function execute(string $receiptId, array $lines, string $reason, string $actorId): PurchaseReturn
    {
        return DB::transaction(function () use ($receiptId, $lines, $reason, $actorId): PurchaseReturn {
            $grn = GoodsReceipt::query()->lockForUpdate()->findOrFail($receiptId);
            $items = $grn->items()->get()->keyBy('id');

            $return = PurchaseReturn::query()->create([
                'company_id' => $grn->company_id, 'branch_id' => $grn->branch_id, 'warehouse_id' => $grn->warehouse_id,
                'supplier_id' => $grn->supplier_id, 'goods_receipt_id' => $grn->id,
                'return_no' => $this->numbers->next($this->tenant->requireId(), 'purchase_return', $grn->company_id, 'DN'),
                'reason' => $reason, 'subtotal' => '0', 'tax_total' => '0', 'grand_total' => '0', 'stock_cost' => '0',
                'created_by' => $actorId, 'returned_at' => now(),
            ]);

            $subtotal = $tax = $stockCost = '0';
            foreach ($lines as $line) {
                $item = $items->get($line['goods_receipt_item_id']) ?? throw new \DomainException('A returned line is not on this goods receipt.');
                $qty = (string) $line['quantity'];
                if (! Decimal::isPositive($qty)) {
                    continue;
                }
                $left = Decimal::sub((string) $item->quantity, (string) $item->returned_quantity, Decimal::QTY_SCALE);
                if (Decimal::cmp($qty, $left) > 0) {
                    throw new \DomainException('Cannot return more than was received and not yet returned ('.rtrim(rtrim($left, '0'), '.').').');
                }

                $avg = $this->stock->issue(new StockPosting(
                    $grn->company_id, $grn->warehouse_id, $item->product_id, $qty, MovementType::PurchaseReturn, 'purchase_return', $return->id, $reason,
                    batchNo: $item->batch_no,
                ));

                $net = Decimal::mul($qty, (string) $item->unit_cost, 2);
                $lineTax = Decimal::round(Decimal::div(Decimal::mul((string) $item->tax_amount, $qty, 8), (string) $item->quantity, 8), 2);

                $return->items()->create([
                    'goods_receipt_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => $qty,
                    'unit_cost' => $item->unit_cost, 'tax_amount' => $lineTax, 'line_total' => Decimal::add($net, $lineTax),
                ]);
                $item->update(['returned_quantity' => Decimal::add((string) $item->returned_quantity, $qty, Decimal::QTY_SCALE)]);

                $subtotal = Decimal::add($subtotal, $net, 2);
                $tax = Decimal::add($tax, $lineTax, 2);
                $stockCost = Decimal::add($stockCost, Decimal::mul($qty, $avg, 2), 2);
            }

            $total = Decimal::add($subtotal, $tax, 2);
            if (! Decimal::isPositive($total)) {
                throw new \DomainException('Enter a quantity to return on at least one line.');
            }

            $company = $grn->company_id;
            $variance = Decimal::sub($subtotal, $stockCost, 2); // supplier credits more than the stock is now worth → gain
            $journalLines = [
                JournalLineDraft::debit($this->accounts->id($company, 'accounts_payable'), $total, $grn->branch_id, null, 'supplier', $grn->supplier_id),
                JournalLineDraft::credit($this->accounts->id($company, 'inventory'), $stockCost, $grn->branch_id),
            ];
            if (Decimal::isPositive($tax)) {
                $journalLines[] = JournalLineDraft::credit($this->accounts->id($company, 'purchase_tax_receivable'), $tax, $grn->branch_id);
            }
            if (! Decimal::isZero($variance)) {
                $ppv = $this->accounts->id($company, 'purchase_price_variance');
                $journalLines[] = Decimal::isPositive($variance)
                    ? JournalLineDraft::credit($ppv, $variance, $grn->branch_id)
                    : JournalLineDraft::debit($ppv, Decimal::negate($variance, 2), $grn->branch_id);
            }

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $company, postingDate: now()->toDateString(), sourceType: 'purchase_return', sourceId: $return->id,
                description: "Debit note {$return->return_no} against {$grn->grn_no}", lines: $journalLines,
            ));

            $return->update(['subtotal' => $subtotal, 'tax_total' => $tax, 'grand_total' => $total, 'stock_cost' => $stockCost, 'journal_entry_id' => $journal->id]);
            $grn->update(['returned_total' => Decimal::add((string) $grn->returned_total, $total, 2)]);

            $supplier = Supplier::query()->lockForUpdate()->findOrFail($grn->supplier_id);
            $supplier->forceFill(['balance' => Decimal::sub((string) $supplier->balance, $total)])->save();

            // Already billed: the bill is owed less.
            if ($grn->supplier_bill_id !== null) {
                $bill = SupplierBill::query()->lockForUpdate()->find($grn->supplier_bill_id);
                if ($bill !== null) {
                    $less = Decimal::cmp($total, (string) $bill->balance_due) > 0 ? (string) $bill->balance_due : $total;
                    $due = Decimal::sub((string) $bill->balance_due, $less, 2);
                    $bill->update(['balance_due' => $due, 'status' => Decimal::isPositive($due) ? 'open' : 'paid']);
                }
            }

            $this->audit->record('purchasing.return.recorded', 'purchase_return', $return->id, null, ['return_no' => $return->return_no, 'grn_no' => $grn->grn_no, 'total' => $total]);

            return $return->load(['items', 'supplier:id,name,supplier_code']);
        });
    }
}
