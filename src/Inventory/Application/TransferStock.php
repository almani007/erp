<?php

declare(strict_types=1);

namespace Inventory\Application;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Inventory\Domain\Enum\MovementType;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Domain\StockPosting;
use Inventory\Infrastructure\Persistence\Eloquent\StockTransfer;
use Inventory\Infrastructure\Persistence\Eloquent\StockTransferLine;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;

/**
 * Warehouse-to-warehouse transfer inside one company.
 *
 * Dispatch takes the goods out of the source; they belong to neither
 * warehouse (in transit) until the destination confirms receipt at the same
 * cost. No journal is posted: stock stays within one legal entity.
 * Inter-company transfers and partial receipts are not supported yet.
 */
final class TransferStock
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
    ) {}

    /** @param  list<array{product_id: string, quantity: string}>  $lines */
    public function dispatch(Warehouse $source, Warehouse $destination, array $lines, ?string $notes): StockTransfer
    {
        if ($source->id === $destination->id) {
            throw new \DomainException('Source and destination warehouse must differ.');
        }
        if ($source->company_id !== $destination->company_id) {
            throw new \DomainException('Transfers between companies are not supported yet.');
        }

        return DB::transaction(function () use ($source, $destination, $lines, $notes): StockTransfer {
            $transfer = StockTransfer::query()->create([
                'company_id' => $source->company_id,
                'transfer_no' => $this->numbers->next($this->tenant->requireId(), 'stock_transfer', $source->company_id, 'TRF'),
                'source_warehouse_id' => $source->id,
                'destination_warehouse_id' => $destination->id,
                'status' => StockTransfer::IN_TRANSIT,
                'notes' => $notes,
                'dispatched_by' => $this->auth->guard('sanctum')->id(),
                'dispatched_at' => now(),
            ]);

            foreach ($lines as $line) {
                $product = Product::query()->findOrFail($line['product_id']);

                $unitCost = $this->ledger->issue(new StockPosting(
                    $source->company_id, $source->id, $product->id, (string) $line['quantity'],
                    MovementType::TransferOut, 'stock_transfer', $transfer->id,
                ));

                StockTransferLine::query()->create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $unitCost,
                ]);
            }

            $this->audit->record('inventory.transfer.dispatched', 'stock_transfer', $transfer->id, null, ['transfer_no' => $transfer->transfer_no]);

            return $transfer->load(['lines.product:id,sku,name', 'source:id,name', 'destination:id,name']);
        });
    }

    public function receive(string $transferId): StockTransfer
    {
        return DB::transaction(function () use ($transferId): StockTransfer {
            // The row lock makes a double-click or retry receive exactly once.
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transferId);

            if ($transfer->status !== StockTransfer::IN_TRANSIT) {
                throw new \DomainException("Transfer {$transfer->transfer_no} has already been received.");
            }

            foreach ($transfer->lines as $line) {
                $this->ledger->receive(new StockPosting(
                    $transfer->company_id, $transfer->destination_warehouse_id, $line->product_id, (string) $line->quantity,
                    MovementType::TransferIn, 'stock_transfer', $transfer->id,
                ), (string) $line->unit_cost);
            }

            $transfer->update([
                'status' => StockTransfer::RECEIVED,
                'received_by' => $this->auth->guard('sanctum')->id(),
                'received_at' => now(),
            ]);

            $this->audit->record('inventory.transfer.received', 'stock_transfer', $transfer->id, null, ['transfer_no' => $transfer->transfer_no]);

            return $transfer->load(['lines.product:id,sku,name', 'source:id,name', 'destination:id,name']);
        });
    }
}
