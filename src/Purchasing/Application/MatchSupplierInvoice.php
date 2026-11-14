<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Illuminate\Support\Facades\DB;
use Purchasing\Domain\Exception\InvoiceMismatch;
use Purchasing\Infrastructure\Persistence\Eloquent\GoodsReceiptItem;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierInvoice;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierInvoiceItem;

final readonly class MatchSupplierInvoice
{
    /**
     * Three-way match: PO → GRN → Supplier Invoice.
     *
     * @param array{
     *     tenant_id: string,
     *     company_id: string,
     *     supplier_id: string,
     *     po_id: ?string,
     *     grn_id: ?string,
     *     invoice_no: string,
     *     invoice_date: string,
     *     due_date: string,
     *     items: array<array{product_id: string, qty: string, unit_price: string, tax_amount: string}>,
     * } $data
     */
    public function execute(array $data): SupplierInvoice
    {
        return DB::transaction(function () use ($data): SupplierInvoice {
            $netTotal = '0';
            $taxTotal = '0';
            $varianceAmount = '0';

            foreach ($data['items'] as $item) {
                $lineTotal = bcmul($item['qty'], $item['unit_price'], 4);
                $netTotal = bcadd($netTotal, $lineTotal, 4);
                $taxTotal = bcadd($taxTotal, $item['tax_amount'], 4);

                // Match against GRN if provided
                if ($data['grn_id'] !== null) {
                    $grnItem = GoodsReceiptItem::where('grn_id', $data['grn_id'])
                        ->where('product_id', $item['product_id'])
                        ->first();

                    if ($grnItem !== null) {
                        if (bccomp($item['qty'], $grnItem->accepted_qty, 6) !== 0) {
                            throw InvoiceMismatch::quantityVariance($data['invoice_no'], $item['product_id']);
                        }

                        $priceVariance = bcsub($item['unit_price'], $grnItem->unit_cost, 4);
                        if (bccomp($priceVariance, '0', 4) !== 0) {
                            $varianceAmount = bcadd(
                                $varianceAmount,
                                bcmul($priceVariance, $item['qty'], 4),
                                4,
                            );
                        }
                    }
                }
            }

            $grandTotal = bcadd($netTotal, $taxTotal, 4);

            $hasVariance = bccomp($varianceAmount, '0', 4) !== 0;

            $invoice = SupplierInvoice::create([
                'tenant_id' => $data['tenant_id'],
                'company_id' => $data['company_id'],
                'supplier_id' => $data['supplier_id'],
                'po_id' => $data['po_id'],
                'grn_id' => $data['grn_id'],
                'invoice_no' => $data['invoice_no'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'net_total' => $netTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'variance_amount' => $varianceAmount,
                'status' => $hasVariance ? 'disputed' : 'matched',
            ]);

            foreach ($data['items'] as $item) {
                SupplierInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'tax_amount' => $item['tax_amount'],
                    'line_total' => bcmul($item['qty'], $item['unit_price'], 4),
                ]);
            }

            return $invoice->load('items');
        });
    }
}
