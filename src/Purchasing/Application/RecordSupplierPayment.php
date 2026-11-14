<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Illuminate\Support\Facades\DB;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierPayment;
use Shared\Domain\Contract\DocumentNumbers;

final readonly class RecordSupplierPayment
{
    public function __construct(
        private DocumentNumbers $documentNumbers,
    ) {}

    /**
     * @param array{
     *     tenant_id: string,
     *     company_id: string,
     *     supplier_id: string,
     *     amount: string,
     *     method: string,
     *     reference: ?string,
     *     invoice_ids: array<string>,
     * } $data
     */
    public function execute(array $data): SupplierPayment
    {
        return DB::transaction(function () use ($data): SupplierPayment {
            $payment = SupplierPayment::create([
                'tenant_id' => $data['tenant_id'],
                'company_id' => $data['company_id'],
                'supplier_id' => $data['supplier_id'],
                'payment_no' => $this->documentNumbers->next('SP', $data['company_id']),
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_at' => now(),
                'status' => 'completed',
            ]);

            // Mark linked invoices as paid
            if (! empty($data['invoice_ids'])) {
                \Purchasing\Infrastructure\Persistence\Eloquent\SupplierInvoice::whereIn('id', $data['invoice_ids'])
                    ->where('tenant_id', $data['tenant_id'])
                    ->update(['status' => 'paid']);
            }

            return $payment;
        });
    }
}
