<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Illuminate\Support\Facades\DB;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseReturn;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseReturnItem;
use Shared\Domain\Contract\DocumentNumbers;

final readonly class CreateSupplierReturn
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
     *     po_id: ?string,
     *     grn_id: ?string,
     *     reason: string,
     *     created_by: string,
     *     items: array<array{product_id: string, qty: string, unit_cost: string, reason: ?string}>
     * } $data
     */
    public function execute(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data): PurchaseReturn {
            $totalAmount = '0';

            foreach ($data['items'] as $item) {
                $totalAmount = bcadd($totalAmount, bcmul($item['qty'], $item['unit_cost'], 4), 4);
            }

            $return = PurchaseReturn::create([
                'tenant_id' => $data['tenant_id'],
                'company_id' => $data['company_id'],
                'warehouse_id' => $data['warehouse_id'],
                'supplier_id' => $data['supplier_id'],
                'po_id' => $data['po_id'],
                'grn_id' => $data['grn_id'],
                'return_no' => $this->documentNumbers->next('PRET', $data['company_id']),
                'status' => 'draft',
                'reason' => $data['reason'],
                'total_amount' => $totalAmount,
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['items'] as $item) {
                PurchaseReturnItem::create([
                    'return_id' => $return->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => bcmul($item['qty'], $item['unit_cost'], 4),
                    'reason' => $item['reason'] ?? null,
                ]);
            }

            return $return->load('items');
        });
    }
}
