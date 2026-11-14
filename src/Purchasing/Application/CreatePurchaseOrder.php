<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Illuminate\Support\Facades\DB;
use Purchasing\Domain\Enum\PurchaseOrderStatus;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrder;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrderItem;
use Shared\Domain\Contract\DocumentNumbers;

final readonly class CreatePurchaseOrder
{
    public function __construct(
        private DocumentNumbers $documentNumbers,
    ) {}

    /**
     * @param array{
     *     tenant_id: string,
     *     company_id: string,
     *     branch_id: string,
     *     supplier_id: string,
     *     currency: string,
     *     expected_at: ?string,
     *     notes: ?string,
     *     items: array<array{product_id: string, variant_id: ?string, qty: string, unit_id: string, unit_price: string, tax_amount: string}>
     * } $data
     */
    public function execute(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $netTotal = '0';
            $taxTotal = '0';
            $lines = [];

            foreach ($data['items'] as $item) {
                $lineTotal = bcmul($item['qty'], $item['unit_price'], 4);
                $netTotal = bcadd($netTotal, $lineTotal, 4);
                $taxTotal = bcadd($taxTotal, $item['tax_amount'], 4);

                $lines[] = array_merge($item, ['line_total' => $lineTotal]);
            }

            $grandTotal = bcadd($netTotal, $taxTotal, 4);

            $po = PurchaseOrder::create([
                'tenant_id' => $data['tenant_id'],
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'supplier_id' => $data['supplier_id'],
                'po_no' => $this->documentNumbers->next('PO', $data['company_id'], $data['branch_id']),
                'status' => PurchaseOrderStatus::Draft,
                'ordered_at' => now(),
                'expected_at' => $data['expected_at'] ?? null,
                'currency' => $data['currency'],
                'net_total' => $netTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                PurchaseOrderItem::create([
                    'po_id' => $po->id,
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] ?? null,
                    'qty' => $line['qty'],
                    'unit_id' => $line['unit_id'],
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $line['tax_amount'],
                    'line_total' => $line['line_total'],
                ]);
            }

            return $po->load('items');
        });
    }
}
