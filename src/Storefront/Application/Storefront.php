<?php

declare(strict_types=1);

namespace Storefront\Application;

use Bookings\Application\ManageAppointments;
use Identity\Infrastructure\Persistence\Eloquent\User;
use Identity\Infrastructure\Provider\IdentityServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Integration\Application\Messenger;
use Sales\Application\ManageSalesOrders;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;
use Workflow\Application\Notifier;

/**
 * The public online store of one business (`/store/{slug}`): browse the
 * catalogue, order for delivery or pick-up, and book appointments. An order
 * becomes a sales order (source "online") for staff to confirm, deliver and
 * invoice; a booking lands on the calendar. The customer is found by phone
 * or added. Settings group `storefront`: enabled, branch_id, warehouse_id,
 * title, about, whatsapp, min_order, delivery_fee_note, booking_enabled.
 */
final class Storefront
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Settings $settings,
        private readonly DocumentNumbers $numbers,
        private readonly Notifier $notifier,
    ) {}

    /** @return array<string, mixed> the store's settings; throws 404 when the store is off */
    public function open(string $slug): array
    {
        $tenant = DB::table('tenants')->where('slug', $slug)->where('status', 'active')->first(['id', 'legal_name']);
        abort_if($tenant === null, 404);
        $this->tenant->set($tenant->id);
        abort_unless((bool) $this->settings->get('storefront', 'enabled'), 404);

        $s = [];
        foreach (['branch_id', 'warehouse_id', 'title', 'about', 'whatsapp', 'min_order', 'delivery_fee_note', 'booking_enabled'] as $k) {
            $s[$k] = $this->settings->get('storefront', $k);
        }
        $s['title'] = $s['title'] ?: $tenant->legal_name;
        $s['branch_id'] ??= DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('created_at')->value('id');
        $s['warehouse_id'] ??= DB::table('warehouses')->where('branch_id', $s['branch_id'])->where('status', 'active')->orderBy('created_at')->value('id');

        return $s;
    }

    /** @return array<string, mixed> */
    public function catalogue(array $s): array
    {
        $t = $this->tenant->requireId();
        $stock = DB::table('stock_balances')->where('warehouse_id', $s['warehouse_id'])->pluck('on_hand', 'product_id');
        $products = DB::table('products as p')->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.tenant_id', $t)->where('p.status', 'active')->where('p.online', true)->whereNull('p.parent_id')
            ->whereIn('p.product_type', ['goods', 'non_stock', 'composite'])
            ->orderBy('c.name')->orderBy('p.name')->limit(2000)
            ->get(['p.id', 'p.name', 'p.description', 'p.sale_price', 'p.image_url', 'p.product_type', 'c.name as category']);

        return [
            'store' => array_intersect_key($s, array_flip(['title', 'about', 'whatsapp', 'min_order', 'delivery_fee_note', 'booking_enabled'])),
            'products' => $products->map(fn ($p): array => (array) $p + [
                // Exact stock is not published: only whether it can be ordered.
                'available' => $p->product_type !== 'goods' || (float) ($stock[$p->id] ?? 0) > 0,
            ])->values(),
            'services' => $s['booking_enabled'] ? DB::table('products')->where('tenant_id', $t)->where('status', 'active')->where('online', true)
                ->where('product_type', 'service')->whereNotNull('duration_minutes')->orderBy('name')
                ->get(['id', 'name', 'sale_price', 'duration_minutes', 'description']) : [],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function placeOrder(array $s, array $data): array
    {
        return $this->asOwner(function () use ($s, $data): array {
            $customer = $this->customer($data['name'], $data['phone'], $data['address'] ?? null);
            $order = app(ManageSalesOrders::class)->save([
                'doc_type' => 'order', 'warehouse_id' => $s['warehouse_id'], 'customer_id' => $customer->id,
                'doc_date' => today()->toDateString(), 'payment_days' => 0, 'source' => 'online',
                'delivery_address' => $data['address'] ?? null,
                'notes' => trim(($data['fulfilment'] === 'delivery' ? 'Delivery' : 'Pick-up').($data['notes'] ? ': '.$data['notes'] : '')),
                'lines' => array_map(static fn (array $l): array => ['product_id' => $l['product_id'], 'quantity' => (string) $l['quantity']], $data['items']),
            ], false);

            if ($s['min_order'] && (float) $order->grand_total < (float) $s['min_order']) {
                throw new \DomainException('The minimum order is Rs '.number_format((float) $s['min_order']).'.');
            }
            $this->notifier->toRole('branch_manager', 'order', "New online order {$order->doc_no}", "{$customer->name} · Rs ".number_format((float) $order->grand_total, 2), '/sales-documents');
            $this->confirm($data['phone'], "Thank you {$customer->name}. We have your order {$order->doc_no} for Rs ".number_format((float) $order->grand_total, 2).'. We will call to confirm.', 'sales_order', $order->id);

            return ['order_no' => $order->doc_no, 'total' => $order->grand_total, 'lines' => $order->lines->map->only(['quantity', 'unit_price', 'line_total', 'product_id'])];
        });
    }

    /** @param  array<string, mixed>  $data */
    public function book(array $s, array $data): array
    {
        abort_unless((bool) $s['booking_enabled'], 404);

        return $this->asOwner(function () use ($data): array {
            $customer = $this->customer($data['name'], $data['phone'], null);
            $appointment = app(ManageAppointments::class)->book([
                'resource_id' => $data['resource_id'], 'product_id' => $data['product_id'], 'starts_at' => $data['starts_at'],
                'customer_id' => $customer->id, 'customer_name' => $customer->name, 'customer_phone' => $data['phone'],
                'notes' => $data['notes'] ?? null, 'source' => 'online',
            ]);
            $this->notifier->toRole('branch_manager', 'booking', "Online booking {$appointment->appointment_no}", "{$customer->name} · {$appointment->starts_at}", '/appointments');
            $this->confirm($data['phone'], "Booked: {$appointment->appointment_no} on ".$appointment->starts_at->setTimezone(config('app.timezone'))->format('d M h:i A').'. Reply to change it.', 'appointment', $appointment->id);

            return ['appointment_no' => $appointment->appointment_no, 'starts_at' => $appointment->starts_at];
        });
    }

    public function slots(array $s, string $productId, string $date): array
    {
        abort_unless((bool) $s['booking_enabled'], 404);

        return array_map(static fn (array $slot): array => ['resource_id' => $slot['resource_id'], 'with' => $slot['resource'], 'start' => $slot['start']],
            app(ManageAppointments::class)->freeSlots($s['branch_id'], $productId, $date, null));
    }

    private function customer(string $name, string $phone, ?string $address): Customer
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $existing = Customer::query()->whereRaw("regexp_replace(COALESCE(phone, ''), '\\D', '', 'g') = ?", [$digits])->first();
        if ($existing !== null) {
            if ($address && ! $existing->address) {
                $existing->update(['address' => $address]);
            }

            return $existing;
        }
        $t = $this->tenant->requireId();

        return Customer::query()->create([
            'customer_code' => $this->numbers->next($t, 'customer', $t, 'CUS'), 'name' => $name, 'phone' => $phone,
            'address' => $address, 'credit_limit' => '0', 'status' => 'active', 'price_tier' => 'retail',
        ]);
    }

    private function confirm(string $phone, string $text, string $type, string $id): void
    {
        $channel = $this->settings->get('messaging', 'whatsapp_enabled') ? 'whatsapp' : ($this->settings->get('messaging', 'sms_enabled') ? 'sms' : null);
        if ($channel !== null) {
            app(Messenger::class)->send($channel, $phone, $text, null, $type, $id);
        }
    }

    /** Public requests have no user: documents are created on behalf of the business owner. */
    private function asOwner(callable $fn): array
    {
        $owner = User::query()->inCurrentTenant()->role(IdentityServiceProvider::OWNER_ROLE)->where('status', 'active')->orderBy('created_at')->first();
        abort_if($owner === null, 503, 'The store is not ready.');
        Auth::guard('sanctum')->setUser($owner);

        return DB::transaction($fn);
    }
}
