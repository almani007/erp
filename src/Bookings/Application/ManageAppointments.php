<?php

declare(strict_types=1);

namespace Bookings\Application;

use Bookings\Infrastructure\Persistence\Eloquent\Appointment;
use Bookings\Infrastructure\Persistence\Eloquent\BookingResource;
use Carbon\CarbonImmutable;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Sales\Application\RecordSale;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Appointments for salons, clinics, workshops, studios and consultants.
 * A resource (person, room, machine) can hold one appointment at a time,
 * only inside its working hours. Billing goes through the cashier's shift
 * like any POS sale.
 */
final class ManageAppointments
{
    public const array ACTIVE = ['booked', 'confirmed', 'arrived'];

    public function __construct(
        private readonly RecordSale $recordSale,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function book(array $data, ?Appointment $existing = null): Appointment
    {
        return DB::transaction(function () use ($data, $existing): Appointment {
            // Locking the resource serialises bookings for it, so the overlap check holds.
            $resource = BookingResource::query()->where('status', 'active')->lockForUpdate()->findOrFail($data['resource_id']);
            $service = Product::query()->findOrFail($data['product_id']);
            if ($resource->service_ids && ! in_array($service->id, $resource->service_ids, true)) {
                throw new \DomainException("{$resource->name} does not offer {$service->name}.");
            }

            $tz = Branch::query()->whereKey($resource->branch_id)->value('timezone') ?? config('app.timezone');
            // Hours are checked in the branch's own time zone, whatever zone the client sent.
            $start = CarbonImmutable::parse($data['starts_at'])->setTimezone($tz);
            $minutes = (int) ($data['duration_minutes'] ?? $service->duration_minutes ?? 30);
            $end = $start->addMinutes($minutes);

            $this->assertWithinHours($resource, $start, $end);
            $clash = Appointment::query()->where('resource_id', $resource->id)->whereIn('status', self::ACTIVE)
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->first();
            if ($clash !== null) {
                throw new \DomainException("{$resource->name} is already booked {$clash->starts_at->setTimezone($tz)->format('H:i')}–{$clash->ends_at->setTimezone($tz)->format('H:i')} ({$clash->customer_name}).");
            }

            $fields = [
                'branch_id' => $resource->branch_id, 'resource_id' => $resource->id, 'product_id' => $service->id,
                'customer_id' => $data['customer_id'] ?? null, 'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null, 'starts_at' => $start, 'ends_at' => $end,
                'price' => isset($data['price']) ? Decimal::round((string) $data['price'], 2) : Decimal::round((string) $service->sale_price, 2),
                'notes' => $data['notes'] ?? null,
            ];

            if ($existing !== null) {
                if (! in_array($existing->status, self::ACTIVE, true)) {
                    throw new \DomainException("This appointment is {$existing->status}.");
                }
                $existing->update($fields);

                return $existing;
            }

            return Appointment::query()->create($fields + [
                'appointment_no' => $this->numbers->next($this->tenant->requireId(), 'appointment', $resource->branch_id, 'APT'),
                'status' => 'booked',
                'created_by' => (string) $this->auth->guard('sanctum')->id(),
            ]);
        });
    }

    public function setStatus(string $id, string $status): Appointment
    {
        $appointment = Appointment::query()->lockForUpdate()->findOrFail($id);
        $flow = [
            'booked' => ['confirmed', 'arrived', 'no_show', 'cancelled'],
            'confirmed' => ['arrived', 'no_show', 'cancelled'],
            'arrived' => ['done', 'cancelled'],
        ];
        if (! in_array($status, $flow[$appointment->status] ?? [], true)) {
            throw new \DomainException("The appointment is {$appointment->status}; it cannot become {$status}.");
        }
        $appointment->update(['status' => $status]);
        $this->audit->record('bookings.appointment.'.$status, 'appointment', $appointment->id, null, ['no' => $appointment->appointment_no]);

        return $appointment;
    }

    /**
     * Bill the appointment (and any products sold with it) at the till.
     *
     * @param  list<array{method: string, amount: string}>  $tenders
     * @param  list<array{product_id: string, quantity: string}>  $extras
     */
    public function bill(string $id, array $tenders, array $extras, ?string $idempotencyKey): Sale
    {
        return DB::transaction(function () use ($id, $tenders, $extras, $idempotencyKey): Sale {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($id);
            if ($appointment->sale_id !== null) {
                throw new \DomainException('This appointment is already billed.');
            }
            if (in_array($appointment->status, ['cancelled', 'no_show'], true)) {
                throw new \DomainException("The appointment is {$appointment->status}.");
            }

            $lines = [['product_id' => $appointment->product_id, 'quantity' => '1', 'unit_price' => (string) $appointment->price]];
            foreach ($extras as $e) {
                $lines[] = ['product_id' => $e['product_id'], 'quantity' => (string) $e['quantity']];
            }

            $sale = $this->recordSale->execute(
                cashierId: (string) $this->auth->guard('sanctum')->id(), lines: $lines, tenders: $tenders,
                customerId: $appointment->customer_id, mayOverridePrice: true, idempotencyKey: $idempotencyKey,
            );
            $appointment->update(['sale_id' => $sale->id, 'status' => 'done']);

            return $sale;
        });
    }

    /**
     * Free start times for a service on a day, in steps of `$step` minutes.
     *
     * @return list<array{resource_id: string, resource: string, start: string}>
     */
    public function freeSlots(string $branchId, string $productId, string $date, ?string $resourceId, int $step = 15): array
    {
        $service = Product::query()->findOrFail($productId);
        $minutes = (int) ($service->duration_minutes ?? 30);
        $tz = Branch::query()->whereKey($branchId)->value('timezone') ?? config('app.timezone');
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();

        $resources = BookingResource::query()->where('branch_id', $branchId)->where('status', 'active')
            ->when($resourceId, fn ($q) => $q->whereKey($resourceId))->get()
            ->filter(fn ($r) => ! $r->service_ids || in_array($productId, $r->service_ids, true));

        $slots = [];
        foreach ($resources as $resource) {
            $booked = Appointment::query()->where('resource_id', $resource->id)->whereIn('status', self::ACTIVE)
                ->where('starts_at', '<', $day->endOfDay())->where('ends_at', '>', $day)->get(['starts_at', 'ends_at']);
            foreach ($resource->working_hours[(string) $day->dayOfWeekIso] ?? [] as [$from, $to]) {
                $cursor = $day->setTimeFromTimeString($from);
                $close = $day->setTimeFromTimeString($to);
                while ($cursor->addMinutes($minutes)->lte($close)) {
                    $end = $cursor->addMinutes($minutes);
                    $free = $cursor->isFuture() && ! $booked->contains(fn ($b) => $b->starts_at < $end && $b->ends_at > $cursor);
                    if ($free) {
                        $slots[] = ['resource_id' => $resource->id, 'resource' => $resource->name, 'start' => $cursor->toIso8601String()];
                    }
                    $cursor = $cursor->addMinutes($step);
                }
            }
        }

        return $slots;
    }

    private function assertWithinHours(BookingResource $resource, CarbonImmutable $start, CarbonImmutable $end): void
    {
        foreach ($resource->working_hours[(string) $start->dayOfWeekIso] ?? [] as [$from, $to]) {
            if ($start->format('H:i') >= $from && $end->format('H:i') <= $to && $start->isSameDay($end)) {
                return;
            }
        }
        throw new \DomainException("{$resource->name} is not working at {$start->format('D H:i')}.");
    }
}
