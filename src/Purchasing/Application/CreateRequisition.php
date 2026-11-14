<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Illuminate\Support\Facades\DB;
use Purchasing\Domain\Enum\RequisitionStatus;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseRequisition;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseRequisitionItem;
use Shared\Domain\Contract\DocumentNumbers;

final readonly class CreateRequisition
{
    public function __construct(
        private DocumentNumbers $documentNumbers,
    ) {}

    /**
     * @param array{
     *     tenant_id: string,
     *     company_id: string,
     *     branch_id: string,
     *     requested_by: string,
     *     notes: ?string,
     *     items: array<array{product_id: string, qty: string, unit_id: string, estimated_cost: ?string, justification: ?string}>
     * } $data
     */
    public function execute(array $data): PurchaseRequisition
    {
        return DB::transaction(function () use ($data): PurchaseRequisition {
            $requisition = PurchaseRequisition::create([
                'tenant_id' => $data['tenant_id'],
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'requisition_no' => $this->documentNumbers->next('PR', $data['company_id'], $data['branch_id']),
                'status' => RequisitionStatus::Draft,
                'requested_by' => $data['requested_by'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                PurchaseRequisitionItem::create([
                    'requisition_id' => $requisition->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'unit_id' => $item['unit_id'],
                    'estimated_cost' => $item['estimated_cost'] ?? null,
                    'justification' => $item['justification'] ?? null,
                ]);
            }

            return $requisition->load('items');
        });
    }
}
