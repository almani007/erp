<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Identity\Infrastructure\Provider\IdentityServiceProvider;
use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrder;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrderItem;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Purchase order lifecycle: draft → approved → (partially) received, or cancelled.
 * A purchase order is a commitment, not an accounting event: nothing is
 * posted until goods are received.
 */
final class ManagePurchaseOrder
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{supplier_id: string, order_date: string, expected_date?: string|null, notes?: string|null}  $header
     * @param  list<array{product_id: string, quantity: string|int|float, unit_cost: string|int|float, tax_rate?: string|int|float|null}>  $lines
     */
    public function create(Warehouse $warehouse, array $header, array $lines, string $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($warehouse, $header, $lines, $actorId): PurchaseOrder {
            $supplier = Supplier::query()->where('status', 'active')->findOrFail($header['supplier_id']);

            $order = PurchaseOrder::query()->create([
                'company_id' => $warehouse->company_id,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier->id,
                'po_no' => $this->numbers->next($this->tenant->requireId(), 'purchase_order', $warehouse->company_id, 'PO'),
                'status' => PurchaseOrder::DRAFT,
                'order_date' => $header['order_date'],
                'expected_date' => $header['expected_date'] ?? null,
                'notes' => $header['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            $subtotal = $tax = '0';

            foreach ($lines as $line) {
                $product = Product::query()->with('taxCode')->findOrFail($line['product_id']);

                if (! $product->product_type->tracksInventory()) {
                    throw new \DomainException("{$product->name} is not a stock item; only stocked goods can be ordered here for now.");
                }

                $rate = (string) ($line['tax_rate'] ?? $product->taxCode?->rate ?? '0');
                $net = Decimal::mul((string) $line['quantity'], (string) $line['unit_cost'], 2);

                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'tax_rate' => $rate,
                ]);

                $subtotal = Decimal::add($subtotal, $net);
                $tax = Decimal::add($tax, Decimal::mul($net, Decimal::div($rate, '100', 8), 2));
            }

            $order->update(['subtotal' => $subtotal, 'tax_total' => $tax, 'grand_total' => Decimal::add($subtotal, $tax)]);

            return $order->load(['items.product:id,sku,name', 'supplier:id,name,supplier_code']);
        });
    }

    /** Maker-checker: the person who raised the order cannot approve it (a tenant owner may). */
    public function approve(string $orderId, object $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($orderId, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->status !== PurchaseOrder::DRAFT) {
                throw new \DomainException("Purchase order {$order->po_no} is {$order->status}, not a draft.");
            }
            if ($order->created_by === $actor->id && ! $actor->hasRole(IdentityServiceProvider::OWNER_ROLE)) {
                throw new \DomainException('You raised this purchase order, so someone else must approve it.');
            }

            $order->update(['status' => PurchaseOrder::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record('purchasing.order.approved', 'purchase_order', $order->id, ['status' => 'draft'], ['status' => 'approved', 'grand_total' => (string) $order->grand_total]);

            return $order;
        });
    }

    public function cancel(string $orderId, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($orderId, $reason): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);

            if (! in_array($order->status, [PurchaseOrder::DRAFT, PurchaseOrder::APPROVED], true)) {
                throw new \DomainException("Purchase order {$order->po_no} already has receipts and cannot be cancelled.");
            }

            $before = $order->status;
            $order->update(['status' => PurchaseOrder::CANCELLED]);
            $this->audit->record('purchasing.order.cancelled', 'purchase_order', $order->id, ['status' => $before], ['status' => 'cancelled'], $reason);

            return $order;
        });
    }
}
