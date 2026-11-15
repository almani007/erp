<?php

declare(strict_types=1);

namespace Sales\Application;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Sales\Infrastructure\Persistence\Eloquent\DeliveryNote;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Deliveries: a delivery note (challan) for an invoice, sent with a driver,
 * and closed as delivered (with the receiver's name, a signature and any
 * cash collected) or failed.
 *
 * Stock already left on the invoice; the note tracks the goods on the road.
 * An invoice can go in several notes (part deliveries); a note cannot carry
 * more than was sold less what was returned and what other open notes carry.
 * Cash on delivery: the amount collected is recorded as a customer receipt
 * against the invoice.
 */
final class ManageDeliveries
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
        private readonly ReceiveCustomerPayment $receipts,
    ) {}

    /**
     * @param  array{sale_id: string, lines?: list<array{sale_item_id: string, quantity: string}>|null, ship_to_name?: string|null, ship_to_phone?: string|null, ship_to_address?: string|null, scheduled_for?: string|null, driver_employee_id?: string|null, driver_name?: string|null, vehicle_no?: string|null, collect_cash?: bool}  $data
     */
    public function create(array $data): DeliveryNote
    {
        return DB::transaction(function () use ($data): DeliveryNote {
            $sale = Sale::query()->lockForUpdate()->findOrFail($data['sale_id']);
            if ($sale->status !== 'completed') {
                throw new \DomainException("Invoice {$sale->sale_no} is {$sale->status}.");
            }
            $open = $this->deliverable($sale);
            $requested = $data['lines'] ?? null;
            $lines = [];
            foreach ($open as $itemId => $row) {
                $qty = $requested === null ? $row['open'] : (string) (collect($requested)->firstWhere('sale_item_id', $itemId)['quantity'] ?? '0');
                if (! Decimal::isPositive($qty)) {
                    continue;
                }
                if (Decimal::cmp($qty, $row['open'], 6) > 0) {
                    throw new \DomainException("Only {$row['open']} of {$row['description']} is left to deliver.");
                }
                $lines[] = ['sale_item_id' => $itemId, 'product_id' => $row['product_id'], 'description' => $row['description'], 'quantity' => $qty];
            }
            if ($requested !== null && collect($requested)->contains(fn ($l) => ! isset($open[$l['sale_item_id']]) && Decimal::isPositive((string) $l['quantity']))) {
                throw new \DomainException('A line does not belong to this invoice or has nothing left to deliver.');
            }
            if ($lines === []) {
                throw new \DomainException("Everything on invoice {$sale->sale_no} is already delivered or on its way.");
            }

            $customer = $sale->customer_id !== null ? Customer::query()->find($sale->customer_id) : null;
            $collect = (bool) ($data['collect_cash'] ?? false);
            $cod = $collect ? Decimal::round((string) ($sale->balance_due ?? '0'), 2) : '0';
            if ($collect && $customer === null) {
                throw new \DomainException('Cash on delivery needs an invoice with a customer.');
            }

            $note = DeliveryNote::query()->create([
                'company_id' => $sale->company_id,
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'note_no' => $this->numbers->next($this->tenant->requireId(), 'delivery_note', $sale->company_id, 'DN'),
                'status' => 'pending',
                'ship_to_name' => $data['ship_to_name'] ?? $customer?->name,
                'ship_to_phone' => $data['ship_to_phone'] ?? $customer?->phone,
                'ship_to_address' => $data['ship_to_address'] ?? $customer?->address,
                'scheduled_for' => $data['scheduled_for'] ?? now()->toDateString(),
                'driver_employee_id' => $data['driver_employee_id'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'vehicle_no' => $data['vehicle_no'] ?? null,
                'cod_amount' => $cod,
                'created_by' => $this->auth->guard('sanctum')->id(),
            ]);
            foreach ($lines as $line) {
                $note->lines()->create($line);
            }

            $this->audit->record('sales.delivery.created', 'delivery_note', $note->id, null, ['note_no' => $note->note_no, 'sale' => $sale->sale_no, 'cod' => $cod]);

            return $this->present($note);
        });
    }

    /** @param  array{driver_employee_id?: string|null, driver_name?: string|null, vehicle_no?: string|null}  $data */
    public function dispatch(string $noteId, array $data): DeliveryNote
    {
        return DB::transaction(function () use ($noteId, $data): DeliveryNote {
            $note = $this->locked($noteId, ['pending', 'failed']);
            $driver = array_filter([
                'driver_employee_id' => $data['driver_employee_id'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'vehicle_no' => $data['vehicle_no'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');
            if (($driver['driver_employee_id'] ?? $note->driver_employee_id) === null && ($driver['driver_name'] ?? $note->driver_name) === null) {
                throw new \DomainException('Say who is taking the delivery.');
            }
            $note->update($driver + ['status' => 'dispatched', 'dispatched_at' => now(), 'outcome_note' => null]);
            $this->audit->record('sales.delivery.dispatched', 'delivery_note', $note->id, null, ['note_no' => $note->note_no]);

            return $this->present($note);
        });
    }

    /**
     * @param  array{received_by: string, signature?: string|null, collected_amount?: string|null, collected_by?: string|null, note?: string|null}  $data
     */
    public function deliver(string $noteId, array $data): DeliveryNote
    {
        return DB::transaction(function () use ($noteId, $data): DeliveryNote {
            $note = $this->locked($noteId, ['dispatched']);
            $collected = Decimal::round((string) ($data['collected_amount'] ?? '0'), 2);
            $paymentId = null;

            if (Decimal::isPositive($collected)) {
                if ($note->customer_id === null) {
                    throw new \DomainException('Cash can only be collected against an invoice with a customer.');
                }
                $sale = Sale::query()->findOrFail($note->sale_id);
                if (Decimal::cmp($collected, Decimal::round((string) $sale->balance_due, 2)) > 0) {
                    throw new \DomainException('The amount collected is more than the invoice still owes ('.Decimal::round((string) $sale->balance_due, 2).').');
                }
                $payment = $this->receipts->execute($note->customer_id, $note->company_id, $note->branch_id, $data['collected_by'] ?? 'cash', $collected,
                    "Collected on delivery {$note->note_no}", (string) $this->auth->guard('sanctum')->id(), [$sale->id => $collected]);
                $paymentId = $payment->id;
            }

            $signature = $data['signature'] ?? null;
            if ($signature !== null && ! str_starts_with($signature, 'data:image/png;base64,')) {
                throw new \DomainException('The signature must be a PNG image.');
            }

            $note->update([
                'status' => 'delivered', 'delivered_at' => now(), 'received_by' => $data['received_by'], 'signature' => $signature,
                'collected_amount' => $collected, 'customer_payment_id' => $paymentId, 'outcome_note' => $data['note'] ?? null,
            ]);
            $this->audit->record('sales.delivery.delivered', 'delivery_note', $note->id, null, ['note_no' => $note->note_no, 'received_by' => $data['received_by'], 'collected' => $collected]);

            return $this->present($note);
        });
    }

    /** Not delivered (customer away, address wrong): back to the shop, ready to send again. */
    public function fail(string $noteId, string $reason): DeliveryNote
    {
        return DB::transaction(function () use ($noteId, $reason): DeliveryNote {
            $note = $this->locked($noteId, ['dispatched']);
            $note->update(['status' => 'failed', 'outcome_note' => $reason]);
            $this->audit->record('sales.delivery.failed', 'delivery_note', $note->id, null, ['note_no' => $note->note_no], $reason);

            return $this->present($note);
        });
    }

    public function cancel(string $noteId, string $reason): DeliveryNote
    {
        return DB::transaction(function () use ($noteId, $reason): DeliveryNote {
            $note = $this->locked($noteId, ['pending', 'failed']);
            $note->update(['status' => 'cancelled', 'outcome_note' => $reason]);
            $this->audit->record('sales.delivery.cancelled', 'delivery_note', $note->id, null, ['note_no' => $note->note_no], $reason);

            return $this->present($note);
        });
    }

    /**
     * What is left to deliver on an invoice, per sale line.
     *
     * @return array<string, array{product_id: string, description: string, sold: string, returned: string, in_notes: string, open: string}>
     */
    public function deliverable(Sale $sale): array
    {
        $items = DB::table('sale_items')->where('sale_id', $sale->id)->get(['id', 'product_id', 'product_name', 'sku', 'quantity']);
        $returned = DB::table('sales_return_items')->whereIn('sale_item_id', $items->pluck('id'))
            ->groupBy('sale_item_id')->selectRaw('sale_item_id, sum(quantity) as q')->pluck('q', 'sale_item_id');
        $inNotes = DB::table('delivery_note_lines as l')->join('delivery_notes as n', 'n.id', '=', 'l.delivery_note_id')
            ->where('n.sale_id', $sale->id)->where('n.status', '!=', 'cancelled')
            ->groupBy('l.sale_item_id')->selectRaw('l.sale_item_id, sum(l.quantity) as q')->pluck('q', 'sale_item_id');

        $out = [];
        foreach ($items as $item) {
            $ret = (string) ($returned[$item->id] ?? '0');
            $sent = (string) ($inNotes[$item->id] ?? '0');
            $open = Decimal::sub(Decimal::sub((string) $item->quantity, $ret, 6), $sent, 6);
            if (Decimal::isPositive($open)) {
                $out[$item->id] = [
                    'product_id' => $item->product_id, 'description' => "{$item->sku} — {$item->product_name}",
                    'sold' => (string) $item->quantity, 'returned' => $ret, 'in_notes' => $sent, 'open' => $open,
                ];
            }
        }

        return $out;
    }

    /** @param  list<string>  $allowed */
    private function locked(string $id, array $allowed): DeliveryNote
    {
        $note = DeliveryNote::query()->lockForUpdate()->findOrFail($id);
        if (! in_array($note->status, $allowed, true)) {
            throw new \DomainException("Delivery {$note->note_no} is {$note->status}.");
        }

        return $note;
    }

    private function present(DeliveryNote $note): DeliveryNote
    {
        return $note->refresh()->load(['lines', 'sale:id,sale_no,grand_total,balance_due,sold_at', 'customer:id,name,customer_code,phone']);
    }
}
