<?php

declare(strict_types=1);

namespace Sales\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Domain\Contract\BranchContext;
use Sales\Application\ManageDeliveries;
use Sales\Infrastructure\Persistence\Eloquent\DeliveryNote;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Shared\Infrastructure\Validation\TenantRule;

final class DeliveryController
{
    public function index(Request $request, BranchContext $branches): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', 'in:pending,dispatched,delivered,failed,cancelled,open'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'driver_employee_id' => ['nullable', 'uuid'],
        ]);

        $rows = DeliveryNote::query()->with(['sale:id,sale_no,grand_total,balance_due', 'customer:id,name,customer_code'])->withCount('lines')
            ->when($branches->allowedBranchIds() !== null, fn ($q) => $q->whereIn('branch_id', $branches->allowedBranchIds()))
            ->when(($f['status'] ?? null) === 'open', fn ($q) => $q->whereIn('status', ['pending', 'dispatched', 'failed']))
            ->when(($f['status'] ?? null) !== null && $f['status'] !== 'open', fn ($q) => $q->where('status', $f['status']))
            ->when($f['date'] ?? null, fn ($q, $d) => $q->where('scheduled_for', $d))
            ->when($f['driver_employee_id'] ?? null, fn ($q, $d) => $q->where('driver_employee_id', $d))
            ->orderByDesc('scheduled_for')->orderByDesc('created_at')->limit(3000)->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function show(string $id): JsonResponse
    {
        $note = DeliveryNote::query()->with(['lines', 'sale:id,sale_no,grand_total,balance_due,sold_at', 'customer:id,name,customer_code,phone,address'])->findOrFail($id);
        $driver = $note->driver_employee_id ? DB::table('employees')->where('id', $note->driver_employee_id)->value('name') : null;

        return new JsonResponse(['data' => $note->toArray() + ['driver' => $driver ?? $note->driver_name, 'signature' => $note->signature]]);
    }

    /** What is left to deliver on an invoice, found by its number. */
    public function deliverable(string $saleNo, ManageDeliveries $deliveries): JsonResponse
    {
        $sale = Sale::query()->where('sale_no', $saleNo)->with('customer:id,name,phone,address')->first();
        abort_if($sale === null, 404, "No invoice {$saleNo}.");

        return new JsonResponse(['data' => [
            'sale' => $sale->only(['id', 'sale_no', 'grand_total', 'balance_due', 'status', 'customer_id']) + ['customer' => $sale->customer],
            'lines' => collect($deliveries->deliverable($sale))->map(fn ($row, $id) => ['sale_item_id' => $id] + $row)->values(),
        ]]);
    }

    public function store(Request $request, ManageDeliveries $deliveries): JsonResponse
    {
        $data = $request->validate([
            'sale_id' => ['required', 'uuid', TenantRule::exists('sales')],
            'lines' => ['nullable', 'array', 'max:500'],
            'lines.*.sale_item_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'ship_to_name' => ['nullable', 'string', 'max:160'],
            'ship_to_phone' => ['nullable', 'string', 'max:40'],
            'ship_to_address' => ['nullable', 'string', 'max:500'],
            'scheduled_for' => ['nullable', 'date_format:Y-m-d'],
            'driver_employee_id' => ['nullable', 'uuid', TenantRule::exists('employees')],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'vehicle_no' => ['nullable', 'string', 'max:40'],
            'collect_cash' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['lines'])) {
            $data['lines'] = array_map(fn ($l) => ['sale_item_id' => $l['sale_item_id'], 'quantity' => (string) $l['quantity']], $data['lines']);
        }

        return new JsonResponse(['data' => $deliveries->create($data)], 201);
    }

    public function dispatch(Request $request, string $id, ManageDeliveries $deliveries): JsonResponse
    {
        $data = $request->validate([
            'driver_employee_id' => ['nullable', 'uuid', TenantRule::exists('employees')],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'vehicle_no' => ['nullable', 'string', 'max:40'],
        ]);

        return new JsonResponse(['data' => $deliveries->dispatch($id, $data)]);
    }

    public function deliver(Request $request, string $id, ManageDeliveries $deliveries): JsonResponse
    {
        $data = $request->validate([
            'received_by' => ['required', 'string', 'min:2', 'max:120'],
            'signature' => ['nullable', 'string', 'max:400000'],
            'collected_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'collected_by' => ['nullable', 'in:cash,bank'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $data['collected_amount'] = isset($data['collected_amount']) ? (string) $data['collected_amount'] : null;

        return new JsonResponse(['data' => $deliveries->deliver($id, $data)]);
    }

    public function fail(Request $request, string $id, ManageDeliveries $deliveries): JsonResponse
    {
        return new JsonResponse(['data' => $deliveries->fail($id, $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'])]);
    }

    public function cancel(Request $request, string $id, ManageDeliveries $deliveries): JsonResponse
    {
        return new JsonResponse(['data' => $deliveries->cancel($id, $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'])]);
    }
}
