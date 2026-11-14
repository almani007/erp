<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Illuminate\Support\Facades\DB;
use Purchasing\Domain\Enum\GrnStatus;
use Purchasing\Domain\Enum\PurchaseOrderStatus;
use Purchasing\Domain\Event\GoodsReceived;
use Purchasing\Domain\Exception\QuantityExceedsPO;
use Purchasing\Infrastructure\Persistence\Eloquent\GoodsReceipt;
use Purchasing\Infrastructure\Persistence\Eloquent\GoodsReceiptItem;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrder;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrderItem;
use Shared\Domain\Contract\DocumentNumbers;

final readonly class ReceiveGoods
{
    public function __construct(
        private DocumentNumbers $documentNumbers,
    ) {}

    /**
     * @param array{
     *     tenant_id: string,
     *     company_id: string,
     *     warehouse_id: string,
     *     supplier_id: string,
     *     po_id: string,
     *     received_by: string,
     *     notes: ?string,
     *     items: array<array{po_item_id: string, product_id: string, accepted_qty: string, rejected_qty: string, batch_number: ?string, expiry_date: ?string, unit_cost: string}>
     * } $data
     */
    public function execute(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data): GoodsReceipt {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($data['po_id']);

            // Validate quantities against PO
            foreach ($data['items'] as $item) {
                $poItem = PurchaseOrderItem::lockForUpdate()->findOrFail($item['po_item_id']);
                $totalReceivable = bcsub($poItem->qty, $poItem->received_qty, 6);
                $totalReceiving = bcadd($item['accepted_qty'], $item['rejected_qty'], 6);

                if (bccomp($totalReceiving, $totalReceivable, 6) > 0) {
                    throw QuantityExceedsPO::forItem($item['product_id'], $totalReceiving, $totalReceivable);
                }
            }

            $grn = GoodsReceipt::create([
                'tenant_id' => $data['tenant_id'],
                'company_id' => $data['company_id'],
                'warehouse_id' => $data['warehouse_id'],
                'supplier_id' => $data['supplier_id'],
                'po_id' => $data['po_id'],
                'grn_no' => $this->documentNumbers->next('GRN', $data['company_id'], $data['warehouse_id']),
                'status' => GrnStatus::Received,
                'received_at' => now(),
                'received_by' => $data['received_by'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                GoodsReceiptItem::create([
                    'grn_id' => $grn->id,
                    'po_item_id' => $item['po_item_id'],
                    'product_id' => $item['product_id'],
                    'accepted_qty' => $item['accepted_qty'],
                    'rejected_qty' => $item['rejected_qty'],
                    'batch_number' => $item['batch_number'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'unit_cost' => $item['unit_cost'],
                ]);

                // Update PO item received qty
                $poItem = PurchaseOrderItem::findOrFail($item['po_item_id']);
                $poItem->increment('received_qty', (float) $item['accepted_qty']);
            }

            // Update PO status based on total received
            $this->updatePurchaseOrderStatus($po);

            event(new GoodsReceived(
                goodsReceiptId: $grn->id,
                tenantId: $grn->tenant_id,
                companyId: $grn->company_id,
                warehouseId: $grn->warehouse_id,
                purchaseOrderId: $grn->po_id,
                supplierId: $grn->supplier_id,
                occurredAt: new \DateTimeImmutable(),
            ));

            return $grn->load('items');
        });
    }

    private function updatePurchaseOrderStatus(PurchaseOrder $po): void
    {
        $po->refresh();
        $allReceived = $po->items->every(
            fn (PurchaseOrderItem $item): bool => bccomp($item->received_qty, $item->qty, 6) >= 0
        );

        $newStatus = $allReceived
            ? PurchaseOrderStatus::Received
            : PurchaseOrderStatus::PartiallyReceived;

        if ($po->status->canTransitionTo($newStatus)) {
            $po->update(['status' => $newStatus]);
        }
    }
}
