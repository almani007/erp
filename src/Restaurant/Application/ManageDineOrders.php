<?php

declare(strict_types=1);

namespace Restaurant\Application;

use Catalog\Application\ResolvePricing;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Restaurant\Infrastructure\Persistence\Eloquent\DineOrder;
use Restaurant\Infrastructure\Persistence\Eloquent\DineOrderLine;
use Restaurant\Infrastructure\Persistence\Eloquent\DiningTable;
use Restaurant\Infrastructure\Persistence\Eloquent\ModifierGroup;
use Sales\Application\RecordSale;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Restaurant order flow: open (table / takeaway / delivery) → add items with
 * modifiers → send to kitchen (KOT) → kitchen prepares → bill → settle.
 *
 * Settling records an ordinary sale through the cashier's open shift, so
 * stock (recipes take their ingredients out), tax, the journal and the
 * cash count all work exactly as at the till.
 */
final class ManageDineOrders
{
    public function __construct(
        private readonly RecordSale $recordSale,
        private readonly ResolvePricing $pricing,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly Settings $settings,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function open(string $branchId, array $data): DineOrder
    {
        return DB::transaction(function () use ($branchId, $data): DineOrder {
            if (($data['order_type'] ?? 'dine_in') === 'dine_in') {
                $table = DiningTable::query()->where('branch_id', $branchId)->where('status', 'active')->findOrFail($data['table_id'] ?? null);
                if (DineOrder::query()->where('table_id', $table->id)->whereIn('status', ['open', 'billed'])->exists()) {
                    throw new \DomainException("Table {$table->name} already has an open order.");
                }
            }

            $order = DineOrder::query()->create([
                'branch_id' => $branchId,
                'table_id' => $data['order_type'] === 'dine_in' ? $data['table_id'] : null,
                'order_no' => $this->numbers->next($this->tenant->requireId(), 'dine_order', $branchId, 'R'),
                'order_type' => $data['order_type'],
                'guests' => $data['guests'] ?? 1,
                'waiter_id' => $data['waiter_id'] ?? $this->userId(),
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'status' => 'open',
                'service_charge_percent' => $data['order_type'] === 'dine_in'
                    ? (string) ($this->settings->get('restaurant', 'service_charge_percent') ?? '0') : '0',
                'notes' => $data['notes'] ?? null,
                'opened_by' => $this->userId(),
                'opened_at' => now(),
            ]);

            return $this->present($order);
        });
    }

    /**
     * @param  list<array{product_id: string, quantity: string, modifier_option_ids?: list<string>, note?: string|null}>  $items
     */
    public function addItems(string $orderId, array $items): DineOrder
    {
        return DB::transaction(function () use ($orderId, $items): DineOrder {
            $order = $this->editable($orderId);
            $products = Product::query()->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
            $this->pricing->load($order->branch_id, null, $products->keys()->all(), null);

            foreach ($items as $item) {
                $product = $products[$item['product_id']] ?? throw new \DomainException('A menu item no longer exists.');
                if (! $product->status->isSellable()) {
                    throw new \DomainException("{$product->name} is not available.");
                }

                [$modifiers, $extra] = $this->modifiers($product->id, $item['modifier_option_ids'] ?? []);
                $base = $this->pricing->unitPrice($product, 'retail', (string) $item['quantity'], null);

                DineOrderLine::query()->create([
                    'dine_order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => Decimal::add($base, $extra, 4),
                    'modifiers' => $modifiers ?: null,
                    'note' => $item['note'] ?? null,
                    'station' => $product->kitchen_station,
                    // Items with no kitchen station (drinks from the counter) need no ticket.
                    'kitchen_status' => $product->kitchen_station ? 'pending' : 'served',
                    'created_by' => $this->userId(),
                ]);
            }

            return $this->present($order);
        });
    }

    /** Sends everything not yet sent as one kitchen order ticket. */
    public function sendToKitchen(string $orderId): DineOrder
    {
        return DB::transaction(function () use ($orderId): DineOrder {
            $order = $this->editable($orderId);
            $pending = $order->lines()->where('kitchen_status', 'pending')->get();
            if ($pending->isEmpty()) {
                throw new \DomainException('Nothing new to send to the kitchen.');
            }
            $kot = (int) preg_replace('/\D/', '', $this->numbers->next($this->tenant->requireId(), 'kot', $order->branch_id, 'KOT'));
            DineOrderLine::query()->whereIn('id', $pending->pluck('id'))->update(['kitchen_status' => 'sent', 'kot_no' => $kot, 'sent_at' => now()]);

            return $this->present($order);
        });
    }

    public function kitchenStatus(string $lineId, string $status): DineOrderLine
    {
        $line = DineOrderLine::query()->findOrFail($lineId);
        DineOrder::query()->findOrFail($line->dine_order_id); // lines have no tenant column: the order's scope checks it
        $flow = ['sent' => ['preparing', 'ready'], 'preparing' => ['ready'], 'ready' => ['served']];
        if (! in_array($status, $flow[$line->kitchen_status] ?? [], true)) {
            throw new \DomainException("This item is {$line->kitchen_status}.");
        }
        $line->update(['kitchen_status' => $status, 'ready_at' => $status === 'ready' ? now() : $line->ready_at]);

        return $line;
    }

    /** Pending items are simply removed; items already cooking need a reason. */
    public function voidLine(string $orderId, string $lineId, ?string $reason): DineOrder
    {
        return DB::transaction(function () use ($orderId, $lineId, $reason): DineOrder {
            $order = $this->editable($orderId);
            $line = $order->lines()->findOrFail($lineId);
            if ($line->kitchen_status === 'pending') {
                $line->delete();
            } else {
                if (! $reason) {
                    throw new \DomainException('This item was already sent to the kitchen: give a reason to void it.');
                }
                $line->update(['kitchen_status' => 'void', 'void_reason' => $reason]);
                $this->audit->record('restaurant.item.voided', 'dine_order', $order->id, null, ['item' => $line->product_name, 'qty' => $line->quantity], $reason);
            }

            return $this->present($order);
        });
    }

    public function moveTable(string $orderId, string $tableId): DineOrder
    {
        return DB::transaction(function () use ($orderId, $tableId): DineOrder {
            $order = $this->editable($orderId);
            $table = DiningTable::query()->where('branch_id', $order->branch_id)->findOrFail($tableId);
            if (DineOrder::query()->where('table_id', $table->id)->whereIn('status', ['open', 'billed'])->exists()) {
                throw new \DomainException("Table {$table->name} is occupied.");
            }
            $order->update(['table_id' => $table->id, 'order_type' => 'dine_in']);

            return $this->present($order);
        });
    }

    /** The guest asked for the bill: nothing more can be added unless it is reopened. */
    public function bill(string $orderId, bool $reopen = false): DineOrder
    {
        $order = DineOrder::query()->lockForUpdate()->findOrFail($orderId);
        if ($reopen ? $order->status !== 'billed' : $order->status !== 'open') {
            throw new \DomainException("Order {$order->order_no} is {$order->status}.");
        }
        $order->update(['status' => $reopen ? 'open' : 'billed']);

        return $this->present($order);
    }

    /**
     * @param  list<array{method: string, amount: string, reference?: string|null}>  $tenders
     */
    public function settle(string $orderId, array $tenders, ?string $discount, ?string $idempotencyKey): Sale
    {
        return DB::transaction(function () use ($orderId, $tenders, $discount, $idempotencyKey): Sale {
            $order = DineOrder::query()->lockForUpdate()->findOrFail($orderId);
            if (! in_array($order->status, ['open', 'billed'], true)) {
                throw new \DomainException("Order {$order->order_no} is {$order->status}.");
            }

            $lines = [];
            foreach ($order->lines()->where('kitchen_status', '!=', 'void')->get() as $line) {
                $lines[] = ['product_id' => $line->product_id, 'quantity' => (string) $line->quantity, 'unit_price' => (string) $line->unit_price];
            }
            if ($lines === []) {
                throw new \DomainException('The order has no items.');
            }

            // Service charge is added as a line on the configured service product, if any.
            $serviceSku = $this->settings->get('restaurant', 'service_charge_sku');
            if (Decimal::isPositive((string) $order->service_charge_percent) && $serviceSku) {
                $service = Product::query()->where('sku', $serviceSku)->first();
                if ($service !== null) {
                    $food = '0';
                    foreach ($lines as $l) {
                        $food = Decimal::add($food, Decimal::mul($l['unit_price'], $l['quantity'], 4), 4);
                    }
                    $lines[] = ['product_id' => $service->id, 'quantity' => '1',
                        'unit_price' => Decimal::round(Decimal::div(Decimal::mul($food, (string) $order->service_charge_percent, 6), '100', 4), 2)];
                }
            }

            // An order-level discount is spread over the lines by value.
            if ($discount !== null && Decimal::isPositive($discount)) {
                $value = '0';
                foreach ($lines as $l) {
                    $value = Decimal::add($value, Decimal::mul($l['unit_price'], $l['quantity'], 4), 4);
                }
                $left = Decimal::round($discount, 2);
                foreach ($lines as $i => $l) {
                    $share = $i === array_key_last($lines) ? $left
                        : Decimal::round(Decimal::div(Decimal::mul($discount, Decimal::mul($l['unit_price'], $l['quantity'], 4), 6), $value, 6), 2);
                    $lines[$i]['discount'] = $share;
                    $left = Decimal::sub($left, $share, 2);
                }
            }

            $sale = $this->recordSale->execute(
                cashierId: $this->userId(), lines: $lines, tenders: $tenders, customerId: $order->customer_id,
                mayOverridePrice: true, idempotencyKey: $idempotencyKey,
            );

            $order->update(['status' => 'paid', 'sale_id' => $sale->id, 'closed_at' => now()]);
            $order->lines()->whereIn('kitchen_status', ['sent', 'preparing', 'ready'])->update(['kitchen_status' => 'served']);

            return $sale;
        });
    }

    public function cancel(string $orderId, string $reason): DineOrder
    {
        $order = $this->editable($orderId);
        if ($order->lines()->whereNotIn('kitchen_status', ['pending', 'void'])->exists()) {
            throw new \DomainException('Items have gone to the kitchen: void them first, or settle the order.');
        }
        $order->update(['status' => 'cancelled', 'closed_at' => now(), 'notes' => trim(($order->notes ?? '').' Cancelled: '.$reason)]);
        $this->audit->record('restaurant.order.cancelled', 'dine_order', $order->id, null, ['order_no' => $order->order_no], $reason);

        return $this->present($order);
    }

    /**
     * Checks the chosen options against the item's modifier groups.
     *
     * @param  list<string>  $optionIds
     * @return array{0: list<array{group: string, name: string, price: string}>, 1: string}
     */
    private function modifiers(string $productId, array $optionIds): array
    {
        $groups = ModifierGroup::query()->where('status', 'active')
            ->whereIn('id', DB::table('product_modifier_groups')->where('product_id', $productId)->select('modifier_group_id'))->get();

        $chosen = [];
        $extra = '0';
        $used = [];
        foreach ($groups as $group) {
            $picked = array_values(array_filter($group->options, static fn (array $o): bool => in_array($o['id'], $optionIds, true)));
            if (count($picked) < $group->min_select) {
                throw new \DomainException("Choose {$group->name}.");
            }
            if (count($picked) > $group->max_select) {
                throw new \DomainException("Choose at most {$group->max_select} for {$group->name}.");
            }
            foreach ($picked as $o) {
                $chosen[] = ['group' => $group->name, 'name' => $o['name'], 'price' => Decimal::round((string) ($o['price'] ?? '0'), 2)];
                $extra = Decimal::add($extra, (string) ($o['price'] ?? '0'), 4);
                $used[] = $o['id'];
            }
        }
        if (array_diff($optionIds, $used) !== []) {
            throw new \DomainException('A chosen option does not belong to this item.');
        }

        return [$chosen, $extra];
    }

    private function editable(string $orderId): DineOrder
    {
        $order = DineOrder::query()->lockForUpdate()->findOrFail($orderId);
        if ($order->status !== 'open') {
            throw new \DomainException("Order {$order->order_no} is {$order->status}. Reopen the bill to change it.");
        }

        return $order;
    }

    private function userId(): string
    {
        return (string) $this->auth->guard('sanctum')->id();
    }

    public function present(DineOrder $order): DineOrder
    {
        return $order->refresh()->load(['lines', 'table:id,name,area']);
    }
}
