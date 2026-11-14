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
use Purchasing\Infrastructure\Persistence\Eloquent\GoodsReceiptItem;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrder;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\EventOutbox;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * WF-PROC, receiving step: a goods receipt (GRN) against an approved
 * purchase order, full or partial.
 *
 *   Dr Inventory, Purchase tax receivable     Cr Accounts payable
 *
 * The supplier liability is recognised at receipt, at the order's prices.
 * There is no separate supplier-invoice matching step yet, so a price
 * difference on the supplier's invoice has to be adjusted by journal.
 */
final class ReceiveGoods
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /** @param  list<array{purchase_order_item_id: string, quantity: string|int|float, batch_no?: string|null, expiry_date?: string|null}>  $lines */
    public function execute(string $orderId, array $lines, ?string $supplierInvoiceNo, string $actorId): GoodsReceipt
    {
        return DB::transaction(function () use ($orderId, $lines, $supplierInvoiceNo, $actorId): GoodsReceipt {
            // Locking the order serialises concurrent receipts, so the remaining-quantity check holds.
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);

            if (! in_array($order->status, [PurchaseOrder::APPROVED, PurchaseOrder::PARTIALLY_RECEIVED], true)) {
                throw new \DomainException("Purchase order {$order->po_no} is {$order->status}; only approved orders can be received.");
            }

            $receipt = GoodsReceipt::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'warehouse_id' => $order->warehouse_id,
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->id,
                'grn_no' => $this->numbers->next($this->tenant->requireId(), 'goods_receipt', $order->company_id, 'GRN'),
                'supplier_invoice_no' => $supplierInvoiceNo,
                'subtotal' => '0', 'tax_total' => '0', 'grand_total' => '0',
                'received_by' => $actorId,
                'received_at' => now(),
            ]);

            $items = $order->items()->with('product:id,name')->get()->keyBy('id');
            $subtotal = $tax = '0';

            foreach ($lines as $line) {
                $item = $items->get($line['purchase_order_item_id'])
                    ?? throw new \DomainException('A received line does not belong to this purchase order.');

                $qty = (string) $line['quantity'];
                $remaining = Decimal::sub((string) $item->quantity, (string) $item->received_quantity, Decimal::QTY_SCALE);

                if (Decimal::cmp($qty, $remaining) > 0) {
                    throw new \DomainException("{$item->product->name}: only {$this->trim($remaining)} left to receive on this order.");
                }

                $this->stock->receive(new StockPosting(
                    $order->company_id, $order->warehouse_id, $item->product_id, $qty,
                    MovementType::PurchaseReceipt, 'goods_receipt', $receipt->id,
                    batchNo: $line['batch_no'] ?? null, expiryDate: $line['expiry_date'] ?? null,
                ), (string) $item->unit_cost);

                $net = Decimal::mul($qty, (string) $item->unit_cost, 2);
                $lineTax = Decimal::mul($net, Decimal::div((string) $item->tax_rate, '100', 8), 2);

                GoodsReceiptItem::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'unit_cost' => $item->unit_cost,
                    'tax_amount' => $lineTax,
                    'line_total' => Decimal::add($net, $lineTax),
                    'batch_no' => $line['batch_no'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                ]);

                $item->update(['received_quantity' => Decimal::add((string) $item->received_quantity, $qty, Decimal::QTY_SCALE)]);

                $subtotal = Decimal::add($subtotal, $net);
                $tax = Decimal::add($tax, $lineTax);
            }

            $total = Decimal::add($subtotal, $tax);

            $supplier = Supplier::query()->lockForUpdate()->findOrFail($order->supplier_id);
            $supplier->forceFill(['balance' => Decimal::add((string) $supplier->balance, $total)])->save();

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $order->company_id,
                postingDate: now()->toDateString(),
                sourceType: 'goods_receipt',
                sourceId: $receipt->id,
                description: "Goods receipt {$receipt->grn_no} for {$order->po_no} — {$supplier->name}",
                lines: [
                    JournalLineDraft::debit($this->accounts->id($order->company_id, 'inventory'), $subtotal, $order->branch_id),
                    // Only resolve the tax account when there is tax, so tax-free purchasing needs no mapping.
                    ...(Decimal::isPositive($tax)
                        ? [JournalLineDraft::debit($this->accounts->id($order->company_id, 'purchase_tax_receivable'), $tax, $order->branch_id)]
                        : []),
                    JournalLineDraft::credit($this->accounts->id($order->company_id, 'accounts_payable'), $total, $order->branch_id, null, 'supplier', $supplier->id),
                ],
            ));

            $receipt->update(['subtotal' => $subtotal, 'tax_total' => $tax, 'grand_total' => $total, 'journal_entry_id' => $journal->id]);

            $outstanding = $order->items()->whereColumn('received_quantity', '<', 'quantity')->exists();
            $order->update(['status' => $outstanding ? PurchaseOrder::PARTIALLY_RECEIVED : PurchaseOrder::RECEIVED]);

            $this->audit->record('purchasing.goods.received', 'goods_receipt', $receipt->id, null, ['grn_no' => $receipt->grn_no, 'po_no' => $order->po_no, 'grand_total' => $total]);
            $this->outbox->record('purchasing.goods.received', 'goods_receipt', $receipt->id, ['grn_no' => $receipt->grn_no, 'purchase_order_id' => $order->id]);

            return $receipt->load('items');
        });
    }

    private function trim(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
