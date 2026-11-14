<?php

declare(strict_types=1);

namespace Bookings\Presentation\Http\Controller;

use Bookings\Application\ManageAppointments;
use Bookings\Infrastructure\Persistence\Eloquent\Appointment;
use Bookings\Infrastructure\Persistence\Eloquent\BookingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Organization\Domain\Contract\BranchContext;
use Sales\Domain\Enum\PaymentMethod;
use Shared\Infrastructure\Validation\TenantRule;

final class BookingController
{
    public function resources(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        return new JsonResponse(['data' => BookingResource::query()->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))->orderBy('name')->get()]);
    }

    public function saveResource(Request $request, BranchContext $branches, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:100'],
            'kind' => ['sometimes', 'in:staff,room,equipment'],
            'employee_id' => ['nullable', 'uuid', TenantRule::exists('employees')],
            'color' => ['sometimes', 'string', 'max:9'],
            'working_hours' => [$id ? 'sometimes' : 'required', 'array'],
            'working_hours.*' => ['array'],
            'working_hours.*.*' => ['array', 'size:2'],
            'working_hours.*.*.*' => ['date_format:H:i'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['uuid'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        if ($id === null) {
            $data['branch_id'] ??= $branches->requireActiveBranchId();

            return new JsonResponse(['data' => BookingResource::query()->create($data)], 201);
        }
        $resource = BookingResource::query()->findOrFail($id);
        $resource->update($data);

        return new JsonResponse(['data' => $resource]);
    }

    public function index(Request $request, BranchContext $branches): JsonResponse
    {
        $q = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $allowed = $branches->allowedBranchIds();

        return new JsonResponse(['data' => Appointment::query()
            ->when($allowed !== null, fn ($b) => $b->whereIn('branch_id', $allowed))
            ->when($branches->activeBranchId(), fn ($b, $id) => $b->where('branch_id', $id))
            ->where('starts_at', '<', $q['to'])->where('ends_at', '>', $q['from'])
            ->orderBy('starts_at')->limit(3000)->get()]);
    }

    public function store(Request $request, ManageAppointments $action): JsonResponse
    {
        return new JsonResponse(['data' => $action->book($this->validated($request))], 201);
    }

    public function update(Request $request, string $id, ManageAppointments $action): JsonResponse
    {
        return new JsonResponse(['data' => $action->book($this->validated($request), Appointment::query()->findOrFail($id))]);
    }

    public function status(Request $request, string $id, ManageAppointments $action): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'in:confirmed,arrived,done,no_show,cancelled']])['status'];

        return new JsonResponse(['data' => $action->setStatus($id, $status)]);
    }

    public function bill(Request $request, string $id, ManageAppointments $action): JsonResponse
    {
        $data = $request->validate([
            'tenders' => ['required', 'array', 'min:1'],
            'tenders.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'tenders.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'extras' => ['sometimes', 'array'],
            'extras.*.product_id' => ['required', 'uuid'],
            'extras.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return new JsonResponse(['data' => $action->bill($id, $data['tenders'], $data['extras'] ?? [], $request->header('Idempotency-Key'))], 201);
    }

    public function slots(Request $request, ManageAppointments $action, BranchContext $branches): JsonResponse
    {
        $q = $request->validate([
            'product_id' => ['required', 'uuid', TenantRule::exists('products')],
            'date' => ['required', 'date_format:Y-m-d'],
            'resource_id' => ['nullable', 'uuid'],
        ]);

        return new JsonResponse(['data' => $action->freeSlots($branches->requireActiveBranchId(), $q['product_id'], $q['date'], $q['resource_id'] ?? null)]);
    }

    /** Tomorrow's appointments with a ready-to-send WhatsApp reminder. */
    public function reminders(BranchContext $branches): JsonResponse
    {
        $tomorrow = now()->addDay();
        $rows = Appointment::query()->whereIn('status', ManageAppointments::ACTIVE)
            ->when($branches->activeBranchId(), fn ($b, $id) => $b->where('branch_id', $id))
            ->whereBetween('starts_at', [$tomorrow->copy()->startOfDay(), $tomorrow->copy()->endOfDay()])
            ->whereNotNull('customer_phone')->orderBy('starts_at')->get();

        return new JsonResponse(['data' => $rows->map(function (Appointment $a): array {
            $phone = preg_replace('/\D/', '', (string) $a->customer_phone);
            $phone = str_starts_with($phone, '0') ? '92'.substr($phone, 1) : $phone; // Pakistani mobile numbers
            $text = "Assalam-o-Alaikum {$a->customer_name}, a reminder of your appointment tomorrow at {$a->starts_at->format('h:i A')}. Reply to confirm or reschedule.";

            return $a->only(['id', 'appointment_no', 'customer_name', 'customer_phone', 'starts_at', 'status', 'reminded_at'])
                + ['whatsapp_url' => "https://wa.me/{$phone}?text=".rawurlencode($text)];
        })]);
    }

    public function markReminded(string $id): JsonResponse
    {
        $a = Appointment::query()->findOrFail($id);
        $a->update(['reminded_at' => now()]);

        return new JsonResponse(['data' => $a]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'resource_id' => ['required', 'uuid', TenantRule::exists('booking_resources')],
            'product_id' => ['required', 'uuid', TenantRule::exists('products')],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'customer_id' => ['nullable', 'uuid', TenantRule::exists('customers')],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
