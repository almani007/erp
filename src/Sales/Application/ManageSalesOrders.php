<?php

declare(strict_types=1);

namespace Sales\Application;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inventory\Application\StockReservations;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Sales\Domain\SaleOrigin;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Sales\Infrastructure\Persistence\Eloquent\SalesOrder;
use Sales\Infrastructure\Persistence\Eloquent\SalesOrderLine;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Quotation → sales order → invoices (WF-O2C).
 *
 *  quotation: draft → sent → accepted | rejected; converting makes an order.
 *  order:     open → partly_invoiced → invoiced; cancelled while nothing is invoiced.
 *
 * Prices are worked out by the same engine as the till (tiers, packs,
 * branch prices, deals) when the document is saved, and are then fixed:
 * invoices use the agreed price and discount, not today's.
 */
final class ManageSalesOrders
{
    public function __construct(
        private readonly PriceCart $cart,
        private readonly RecordSale $recordSale,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
        private readonly StockReservations $reservations,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array{doc_type: string, warehouse_id: string, customer_id: string, doc_date: string, valid_until?: string|null, delivery_date?: string|null,
     *               payment_days?: int|null, price_tier?: string|null, reference?: string|null, notes?: string|null, terms?: string|null,
     *               lines: list<array{product_id: string, quantity: string, unit_price?: string|null, discount?: string|null, description?: string|null}>}  $data
     */
    public function save(array $data, bool $mayOverridePrice, ?SalesOrder $existing = null): SalesOrder
    {
        return DB::transaction(function () use ($data, $mayOverridePrice, $existing): SalesOrder {
            $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);

            if ($existing !== null) {
                $existing = SalesOrder::query()->lockForUpdate()->findOrFail($existing->id);
                $editable = $existing->doc_type === SalesOrder::QUOTATION
                    ? in_array($existing->status, ['draft', 'sent'], true)
                    : $existing->status === 'open';
                if (! $editable) {
                    throw new \DomainException("{$existing->doc_no} is {$existing->status} and can no longer be edited.");
                }
            }

            $priced = $this->cart->price(
                array_map(static fn (array $l): array => array_filter([
                    'product_id' => $l['product_id'], 'quantity' => $l['quantity'],
                    'unit_price' => $l['unit_price'] ?? null, 'discount' => $l['discount'] ?? null,
                ], static fn ($v): bool => $v !== null && $v !== ''), $data['lines']),
                $mayOverridePrice, $warehouse->branch_id, null, $data['customer_id'], $data['price_tier'] ?? null,
            );

            $header = [
                'warehouse_id' => $warehouse->id,
                'branch_id' => $warehouse->branch_id,
                'company_id' => $warehouse->company_id,
                'customer_id' => $data['customer_id'],
                'doc_date' => $data['doc_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? null,
                'payment_days' => (int) ($data['payment_days'] ?? 0),
                'price_tier' => $priced['price_tier'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'subtotal' => $priced['subtotal'],
                'discount_total' => $priced['discount_total'],
                'tax_total' => $priced['tax_total'],
                'grand_total' => $priced['grand_total'],
            ];

            if ($existing === null) {
                $type = $data['doc_type'];
                $doc = SalesOrder::query()->create($header + [
                    'source' => $data['source'] ?? 'staff',
                    'delivery_address' => $data['delivery_address'] ?? null,
                    'doc_type' => $type,
                    'doc_no' => $this->numbers->next($this->tenant->requireId(), 'sales_'.$type, $warehouse->company_id, $type === SalesOrder::QUOTATION ? 'QT' : 'SO'),
                    'status' => $type === SalesOrder::QUOTATION ? 'draft' : 'open',
                    'created_by' => $this->auth->guard('sanctum')->id(),
                ]);
            } else {
                $doc = $existing;
                $this->releaseAll($doc); // the old lines' stock goes back before the new lines take theirs
                $doc->update($header);
                $doc->lines()->delete();
            }

            foreach ($priced['lines'] as $i => $line) {
                SalesOrderLine::query()->create([
                    'sales_order_id' => $doc->id,
                    'line_no' => $i + 1,
                    'product_id' => $line['product']->id,
                    'description' => $data['lines'][$i]['description'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount' => $line['discount'],
                    'tax_amount' => $line['tax'],
                    'line_total' => $line['total'],
                ]);
            }

            $this->audit->record("sales.{$doc->doc_type}.saved", 'sales_order', $doc->id, null, ['doc_no' => $doc->doc_no, 'total' => $doc->grand_total]);
            $shortages = $doc->doc_type === SalesOrder::ORDER ? $this->reserveFor($doc->refresh()) : [];

            return $this->present($doc)->setAttribute('stock_shortages', $shortages);
        });
    }

    public function setQuotationStatus(string $id, string $status): SalesOrder
    {
        return DB::transaction(function () use ($id, $status): SalesOrder {
            $doc = $this->locked($id, SalesOrder::QUOTATION);
            $allowed = ['draft' => ['sent', 'accepted', 'rejected'], 'sent' => ['accepted', 'rejected'], 'accepted' => ['rejected'], 'rejected' => ['sent']];
            if (! in_array($status, $allowed[$doc->status] ?? [], true)) {
                throw new \DomainException("Quotation {$doc->doc_no} is {$doc->status}; it cannot be marked {$status}.");
            }
            $doc->update(['status' => $status]);
            $this->audit->record('sales.quotation.'.$status, 'sales_order', $doc->id, null, ['doc_no' => $doc->doc_no]);

            return $this->present($doc);
        });
    }

    /** Turn a quotation into a sales order with the same lines and prices. */
    public function convert(string $quotationId): SalesOrder
    {
        return DB::transaction(function () use ($quotationId): SalesOrder {
            $quote = $this->locked($quotationId, SalesOrder::QUOTATION);
            if (! in_array($quote->status, ['draft', 'sent', 'accepted'], true)) {
                throw new \DomainException("Quotation {$quote->doc_no} is {$quote->status} and cannot become an order.");
            }
            if ($quote->valid_until !== null && Carbon::parse($quote->valid_until)->isBefore(today())) {
                throw new \DomainException("Quotation {$quote->doc_no} expired on {$quote->valid_until->toDateString()}. Update its validity or prices first.");
            }

            $order = $quote->replicate(['doc_no', 'status', 'doc_type', 'valid_until', 'created_by']);
            $order->fill([
                'doc_type' => SalesOrder::ORDER,
                'doc_no' => $this->numbers->next($this->tenant->requireId(), 'sales_order', $quote->company_id, 'SO'),
                'status' => 'open',
                'doc_date' => today()->toDateString(),
                'source_id' => $quote->id,
                'created_by' => $this->auth->guard('sanctum')->id(),
            ])->save();

            foreach ($quote->lines as $line) {
                $order->lines()->create($line->only(['line_no', 'product_id', 'description', 'quantity', 'unit_price', 'discount', 'tax_amount', 'line_total']));
            }

            $quote->update(['status' => 'converted']);
            $this->audit->record('sales.quotation.converted', 'sales_order', $quote->id, null, ['doc_no' => $quote->doc_no, 'order_no' => $order->doc_no]);
            $shortages = $this->reserveFor($order->refresh());

            return $this->present($order)->setAttribute('stock_shortages', $shortages);
        });
    }

    public function cancel(string $orderId): SalesOrder
    {
        return DB::transaction(function () use ($orderId): SalesOrder {
            $order = $this->locked($orderId, SalesOrder::ORDER);
            if ($order->status !== 'open') {
                throw new \DomainException("Order {$order->doc_no} is {$order->status}; only an order with nothing invoiced can be cancelled.");
            }
            $this->releaseAll($order);
            $order->update(['status' => 'cancelled']);
            $this->audit->record('sales.order.cancelled', 'sales_order', $order->id, null, ['doc_no' => $order->doc_no]);

            return $this->present($order);
        });
    }

    /**
     * Invoice all or part of an order: stock leaves the order's warehouse,
     * revenue and the receivable are booked, and the invoice is due after
     * the order's payment days.
     *
     * @param  array<string, string>|null  $quantities  line id => quantity; null = everything still open
     * @param  array<string, list<string>>  $serials  line id => serial numbers, for serial-tracked products
     */
    public function invoice(string $orderId, ?array $quantities, string $paidNow, string $paidBy, array $serials, ?string $idempotencyKey): Sale
    {
        return DB::transaction(function () use ($orderId, $quantities, $paidNow, $paidBy, $serials, $idempotencyKey): Sale {
            $order = $this->locked($orderId, SalesOrder::ORDER);
            if (! in_array($order->status, ['open', 'partly_invoiced'], true)) {
                throw new \DomainException("Order {$order->doc_no} is {$order->status}.");
            }

            $lines = [];
            $taken = [];
            foreach ($order->lines as $line) {
                $remaining = Decimal::sub((string) $line->quantity, (string) $line->invoiced_quantity, Decimal::QTY_SCALE);
                $qty = $quantities === null ? $remaining : (string) ($quantities[$line->id] ?? '0');

                if (! Decimal::isPositive($qty)) {
                    continue;
                }
                if (Decimal::cmp($qty, $remaining) > 0) {
                    throw new \DomainException("Line {$line->line_no}: only ".rtrim(rtrim($remaining, '0'), '.').' is left to invoice.');
                }

                // The line discount is shared out in proportion to the quantity invoiced.
                $share = Decimal::round(Decimal::div(Decimal::mul((string) $line->discount, $qty, 8), (string) $line->quantity, 8), 2);
                $lines[] = [
                    'product_id' => $line->product_id, 'quantity' => $qty, 'unit_price' => (string) $line->unit_price,
                    'discount' => $share, 'agreed' => true, 'serials' => $serials[$line->id] ?? [],
                ];
                $taken[] = [$line, $qty];
            }

            if ($lines === []) {
                throw new \DomainException('Nothing to invoice: enter a quantity on at least one line.');
            }

            // The order's own reserved stock is freed for its invoice.
            foreach ($taken as [$line, $qty]) {
                $free = Decimal::cmp($qty, (string) $line->reserved_quantity) > 0 ? (string) $line->reserved_quantity : $qty;
                if (Decimal::isPositive($free)) {
                    $this->reservations->release($order->warehouse_id, $line->product_id, $free);
                    $line->update(['reserved_quantity' => Decimal::sub((string) $line->reserved_quantity, $free, Decimal::QTY_SCALE)]);
                }
            }

            $dueDate = today()->addDays((int) $order->payment_days)->toDateString();
            $sale = $this->recordSale->invoice(
                new SaleOrigin(
                    companyId: $order->company_id, branchId: $order->branch_id, warehouseId: $order->warehouse_id,
                    userId: (string) $this->auth->guard('sanctum')->id(), channel: SaleOrigin::INVOICE,
                    dueDate: $dueDate, salesOrderId: $order->id, reference: $order->reference ?? $order->doc_no, notes: $order->notes,
                ),
                $lines, $order->customer_id, $order->price_tier, $paidNow, $paidBy, $idempotencyKey,
            );

            foreach ($taken as [$line, $qty]) {
                $line->update(['invoiced_quantity' => Decimal::add((string) $line->invoiced_quantity, $qty, Decimal::QTY_SCALE)]);
            }
            $this->refreshStatus($order);
            $this->audit->record('sales.order.invoiced', 'sales_order', $order->id, null, ['doc_no' => $order->doc_no, 'invoice' => $sale->sale_no]);

            return $sale;
        });
    }

    /** A voided invoice's quantities become open on the order again. */
    public function releaseInvoice(Sale $sale): void
    {
        $order = SalesOrder::query()->lockForUpdate()->find($sale->sales_order_id);
        if ($order === null) {
            return;
        }

        foreach ($sale->items as $item) {
            $left = (string) $item->quantity;
            foreach ($order->lines->where('product_id', $item->product_id)->sortByDesc('line_no') as $line) {
                $back = Decimal::cmp($left, (string) $line->invoiced_quantity) > 0 ? (string) $line->invoiced_quantity : $left;
                if (! Decimal::isPositive($back)) {
                    continue;
                }
                $line->update(['invoiced_quantity' => Decimal::sub((string) $line->invoiced_quantity, $back, Decimal::QTY_SCALE)]);
                $left = Decimal::sub($left, $back, Decimal::QTY_SCALE);
            }
        }

        $this->refreshStatus($order->refresh());
        if (in_array($order->status, ['open', 'partly_invoiced'], true)) {
            $this->reserveFor($order); // goods back on the order are held for it again
        }
    }

    /**
     * Reserves what each open line still needs. Returns the lines that could not be fully covered.
     *
     * @return list<array{line_no: int, product_id: string, needed: string, reserved: string, short: string}>
     */
    public function reserveFor(SalesOrder $order): array
    {
        if ($order->doc_type !== SalesOrder::ORDER || ! in_array($order->status, ['open', 'partly_invoiced'], true)
            || $this->settings->get('sales', 'reserve_stock_for_orders', $order->company_id) === false) {
            return [];
        }

        $short = [];
        foreach ($order->lines()->get() as $line) {
            $open = Decimal::sub((string) $line->quantity, (string) $line->invoiced_quantity, Decimal::QTY_SCALE);
            $want = Decimal::sub($open, (string) $line->reserved_quantity, Decimal::QTY_SCALE);
            if (Decimal::isPositive($want)) {
                $got = $this->reservations->reserve($order->warehouse_id, $line->product_id, $want);
                if (Decimal::isPositive($got)) {
                    $line->update(['reserved_quantity' => Decimal::add((string) $line->reserved_quantity, $got, Decimal::QTY_SCALE)]);
                }
                $isStock = DB::table('products')->where('id', $line->product_id)->value('product_type') === 'goods';
                if ($isStock && Decimal::cmp($got, $want) < 0) {
                    $short[] = ['line_no' => (int) $line->line_no, 'product_id' => $line->product_id, 'needed' => $want, 'reserved' => $got, 'short' => Decimal::sub($want, $got, Decimal::QTY_SCALE)];
                }
            }
        }

        return $short;
    }

    private function releaseAll(SalesOrder $order): void
    {
        foreach ($order->lines()->get() as $line) {
            if (Decimal::isPositive((string) $line->reserved_quantity)) {
                $this->reservations->release($order->warehouse_id, $line->product_id, (string) $line->reserved_quantity);
                $line->update(['reserved_quantity' => '0']);
            }
        }
    }

    private function refreshStatus(SalesOrder $order): void
    {
        $lines = $order->lines()->get();
        $ordered = Decimal::sum($lines, 'quantity', Decimal::QTY_SCALE);
        $invoiced = Decimal::sum($lines, 'invoiced_quantity', Decimal::QTY_SCALE);

        $status = match (true) {
            ! Decimal::isPositive($invoiced) => 'open',
            Decimal::cmp($invoiced, $ordered) >= 0 => 'invoiced',
            default => 'partly_invoiced',
        };
        $order->update(['status' => $status]);
    }

    private function locked(string $id, string $type): SalesOrder
    {
        return SalesOrder::query()->where('doc_type', $type)->lockForUpdate()->findOrFail($id);
    }

    private function present(SalesOrder $doc): SalesOrder
    {
        return $doc->refresh()->load(['lines.product:id,sku,name,track_serial', 'customer:id,name,customer_code,phone,email,tax_no,address,payment_days,credit_limit,balance']);
    }
}
