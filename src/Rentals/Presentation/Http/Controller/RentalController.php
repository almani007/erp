<?php

declare(strict_types=1);

namespace Rentals\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Domain\Contract\BranchContext;
use Rentals\Application\ManageRentals;
use Rentals\Infrastructure\Persistence\Eloquent\RentalContract;
use Shared\Domain\Contract\TenantContext;
use Shared\Infrastructure\Validation\TenantRule;

final class RentalController
{
    public function index(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();
        $rows = RentalContract::query()->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('next_bill_date')->limit(3000)->get();
        $names = DB::table('customers')->whereIn('id', $rows->pluck('customer_id'))->pluck('name', 'id');
        $billed = DB::table('sales')->whereIn('rental_contract_id', $rows->pluck('id'))->where('status', 'completed')
            ->groupBy('rental_contract_id')->selectRaw('rental_contract_id, sum(grand_total) as billed, sum(balance_due) as due')->get()->keyBy('rental_contract_id');

        return new JsonResponse(['data' => $rows->map(fn (RentalContract $c): array => $c->toArray() + [
            'customer_name' => $names[$c->customer_id] ?? null,
            'billed' => (string) ($billed[$c->id]->billed ?? '0'), 'outstanding' => (string) ($billed[$c->id]->due ?? '0'),
        ])]);
    }

    public function store(Request $request, ManageRentals $action): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', TenantRule::exists('warehouses')],
            'customer_id' => ['required', 'uuid', TenantRule::exists('customers')],
            'rent_product_id' => ['required', 'uuid', TenantRule::exists('products')],
            'item_product_id' => ['nullable', 'uuid', TenantRule::exists('products')],
            'item_description' => ['required', 'string', 'max:200'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'billing_frequency' => ['required', 'in:daily,weekly,monthly'],
            'rent_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'deposit_method' => ['nullable', 'in:cash,bank'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return new JsonResponse(['data' => $action->start($data)], 201);
    }

    public function bill(ManageRentals $action, TenantContext $tenant): JsonResponse
    {
        return new JsonResponse(['data' => $action->billDue($tenant->requireId())]);
    }

    public function returnItem(Request $request, string $id, ManageRentals $action): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'deduction' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'refund_method' => ['nullable', 'in:cash,bank'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return new JsonResponse(['data' => $action->returnItem($id, $data['date'], (string) ($data['deduction'] ?? '0'), $data['refund_method'] ?? 'cash', $data['notes'] ?? null)]);
    }

    public function invoices(string $id): JsonResponse
    {
        RentalContract::query()->findOrFail($id);

        return new JsonResponse(['data' => DB::table('sales')->where('rental_contract_id', $id)->orderByDesc('sold_at')
            ->get(['id', 'sale_no', 'sold_at', 'due_date', 'grand_total', 'balance_due', 'status', 'notes'])]);
    }
}
