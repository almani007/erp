<?php

declare(strict_types=1);

namespace Restaurant\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Organization\Domain\Contract\BranchContext;
use Restaurant\Application\ManageDineOrders;
use Restaurant\Infrastructure\Persistence\Eloquent\DineOrder;
use Restaurant\Infrastructure\Persistence\Eloquent\DineOrderLine;
use Restaurant\Infrastructure\Persistence\Eloquent\DiningTable;
use Restaurant\Infrastructure\Persistence\Eloquent\ModifierGroup;
use Sales\Domain\Enum\PaymentMethod;
use Shared\Infrastructure\Validation\TenantRule;

final class RestaurantController
{
    public function __construct(private readonly ManageDineOrders $orders) {}

    // ── Floor ────────────────────────────────────────────────────

    /** Tables of the active branch with their open order, if any. */
    public function floor(BranchContext $branches): JsonResponse
    {
        $branchId = $branches->requireActiveBranchId();
        $open = DineOrder::query()->where('branch_id', $branchId)->whereIn('status', ['open', 'billed'])->with('lines')->get();

        $totals = fn (DineOrder $o): array => [
            'id' => $o->id, 'order_no' => $o->order_no, 'status' => $o->status, 'guests' => $o->guests, 'opened_at' => $o->opened_at,
            'order_type' => $o->order_type, 'customer_name' => $o->customer_name,
            'total' => number_format($o->lines->where('kitchen_status', '!=', 'void')->sum(fn ($l) => (float) $l->unit_price * (float) $l->quantity), 2, '.', ''),
            'ready' => $o->lines->where('kitchen_status', 'ready')->count(),
        ];

        return new JsonResponse(['data' => [
            'tables' => DiningTable::query()->where('branch_id', $branchId)->where('status', 'active')->orderBy('area')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (DiningTable $t): array => $t->toArray() + ['order' => ($o = $open->firstWhere('table_id', $t->id)) ? $totals($o) : null]),
            'other_orders' => $open->whereNull('table_id')->values()->map($totals),
        ]]);
    }

    /** Menu: sellable items with their modifier groups. */
    public function menu(): JsonResponse
    {
        $products = DB::table('products as p')->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.tenant_id', app(\Shared\Domain\Contract\TenantContext::class)->requireId())
            ->where('p.status', 'active')->whereNull('p.parent_id')
            ->orderBy('c.name')->orderBy('p.name')
            ->get(['p.id', 'p.sku', 'p.name', 'p.sale_price', 'p.kitchen_station', 'c.name as category']);
        $links = DB::table('product_modifier_groups')->whereIn('product_id', $products->pluck('id'))->orderBy('sort_order')->get()->groupBy('product_id');

        return new JsonResponse(['data' => [
            'items' => $products->map(fn ($p) => (array) $p + ['modifier_group_ids' => ($links[$p->id] ?? collect())->pluck('modifier_group_id')->values()]),
            'modifier_groups' => ModifierGroup::query()->where('status', 'active')->orderBy('name')->get(),
        ]]);
    }

    // ── Orders ───────────────────────────────────────────────────

    public function show(string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->orders->present(DineOrder::query()->findOrFail($id))]);
    }

    public function open(Request $request, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'order_type' => ['required', 'in:dine_in,takeaway,delivery'],
            'table_id' => ['required_if:order_type,dine_in', 'nullable', 'uuid'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:200'],
            'customer_id' => ['nullable', 'uuid', TenantRule::exists('customers')],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'delivery_address' => ['required_if:order_type,delivery', 'nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return new JsonResponse(['data' => $this->orders->open($branches->requireActiveBranchId(), $data)], 201);
    }

    public function addItems(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999'],
            'items.*.modifier_option_ids' => ['sometimes', 'array'],
            'items.*.modifier_option_ids.*' => ['string'],
            'items.*.note' => ['nullable', 'string', 'max:200'],
        ]);
        $items = array_map(static fn (array $i): array => ['quantity' => (string) $i['quantity']] + $i, $data['items']);

        return new JsonResponse(['data' => $this->orders->addItems($id, $items)]);
    }

    public function action(Request $request, string $id, string $action): JsonResponse
    {
        return new JsonResponse(['data' => match ($action) {
            'send' => $this->orders->sendToKitchen($id),
            'bill' => $this->orders->bill($id),
            'reopen' => $this->orders->bill($id, true),
            'move' => $this->orders->moveTable($id, $request->validate(['table_id' => ['required', 'uuid']])['table_id']),
            'cancel' => $this->orders->cancel($id, $request->validate(['reason' => ['required', 'string', 'max:200']])['reason']),
            default => abort(404),
        }]);
    }

    public function voidLine(Request $request, string $id, string $lineId): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:200']])['reason'] ?? null;

        return new JsonResponse(['data' => $this->orders->voidLine($id, $lineId, $reason)]);
    }

    public function settle(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'tenders' => ['required', 'array', 'min:1', 'max:10'],
            'tenders.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'tenders.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tenders.*.reference' => ['nullable', 'string', 'max:120'],
            'discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        if (isset($data['discount']) && $data['discount'] > 0) {
            abort_unless($request->user()->can('sales.price.override'), 403, 'Discounts need the price override permission.');
        }

        $sale = $this->orders->settle($id, $data['tenders'], isset($data['discount']) ? (string) $data['discount'] : null, $request->header('Idempotency-Key'));

        return new JsonResponse(['data' => $sale], 201);
    }

    // ── Kitchen display ──────────────────────────────────────────

    /** Tickets still in the kitchen, oldest first, optionally for one station. */
    public function kitchen(Request $request, BranchContext $branches): JsonResponse
    {
        $station = $request->validate(['station' => ['nullable', 'string', 'max:40']])['station'] ?? null;
        $branchId = $branches->requireActiveBranchId();

        $lines = DineOrderLine::query()->join('dine_orders as o', 'o.id', '=', 'dine_order_lines.dine_order_id')
            ->leftJoin('dining_tables as t', 't.id', '=', 'o.table_id')
            ->where('o.tenant_id', app(\Shared\Domain\Contract\TenantContext::class)->requireId())
            ->where('o.branch_id', $branchId)
            ->whereIn('dine_order_lines.kitchen_status', ['sent', 'preparing', 'ready'])
            ->when($station, fn ($q) => $q->where('dine_order_lines.station', $station))
            ->orderBy('dine_order_lines.sent_at')
            ->get(['dine_order_lines.*', 'o.order_no', 'o.order_type', 't.name as table_name']);

        return new JsonResponse(['data' => $lines->groupBy(fn ($l) => $l->dine_order_id.'#'.$l->kot_no)->map(fn ($g) => [
            'kot_no' => $g->first()->kot_no, 'order_id' => $g->first()->dine_order_id, 'order_no' => $g->first()->order_no,
            'order_type' => $g->first()->order_type, 'table' => $g->first()->table_name, 'sent_at' => $g->first()->sent_at,
            'lines' => $g->map(fn ($l) => $l->only(['id', 'product_name', 'quantity', 'modifiers', 'note', 'station', 'kitchen_status']))->values(),
        ])->values()]);
    }

    public function kitchenStatus(Request $request, string $lineId): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'in:preparing,ready,served']])['status'];

        return new JsonResponse(['data' => $this->orders->kitchenStatus($lineId, $status)]);
    }

    // ── Setup ────────────────────────────────────────────────────

    public function tables(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        return new JsonResponse(['data' => DiningTable::query()->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->orderBy('area')->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function saveTable(Request $request, BranchContext $branches, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => [$id ? 'sometimes' : 'nullable', 'uuid', TenantRule::exists('branches')],
            'area' => ['sometimes', 'string', 'max:60'],
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:30'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        if ($id === null) {
            $data['branch_id'] ??= $branches->requireActiveBranchId();
            abort_unless($branches->canAccess($data['branch_id']), 403);

            return new JsonResponse(['data' => DiningTable::query()->create($data)], 201);
        }
        $table = DiningTable::query()->findOrFail($id);
        $table->update($data);

        return new JsonResponse(['data' => $table]);
    }

    public function modifierGroups(): JsonResponse
    {
        $groups = ModifierGroup::query()->orderBy('name')->get();
        $links = DB::table('product_modifier_groups')->whereIn('modifier_group_id', $groups->pluck('id'))->get()->groupBy('modifier_group_id');

        return new JsonResponse(['data' => $groups->map(fn (ModifierGroup $g) => $g->toArray() + ['product_ids' => ($links[$g->id] ?? collect())->pluck('product_id')->values()])]);
    }

    public function saveModifierGroup(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'min_select' => ['required', 'integer', 'min:0', 'max:20'],
            'max_select' => ['required', 'integer', 'min:1', 'max:20', 'gte:min_select'],
            'options' => ['required', 'array', 'min:1', 'max:40'],
            'options.*.id' => ['nullable', 'string', 'max:40'],
            'options.*.name' => ['required', 'string', 'max:80'],
            'options.*.price' => ['nullable', 'numeric', 'min:-100000', 'max:100000'],
            'product_ids' => ['present', 'array'],
            'product_ids.*' => ['uuid', TenantRule::exists('products')],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        // Option ids stay stable so orders that used them still make sense.
        $data['options'] = array_map(static fn (array $o): array => ['id' => $o['id'] ?? (string) Str::ulid(), 'name' => $o['name'], 'price' => (string) ($o['price'] ?? '0')], $data['options']);

        $group = DB::transaction(function () use ($data, $id): ModifierGroup {
            $fields = collect($data)->except('product_ids')->all();
            $group = $id === null ? ModifierGroup::query()->create($fields) : tap(ModifierGroup::query()->findOrFail($id))->update($fields);
            DB::table('product_modifier_groups')->where('modifier_group_id', $group->id)->delete();
            foreach ($data['product_ids'] as $i => $productId) {
                DB::table('product_modifier_groups')->insert(['product_id' => $productId, 'modifier_group_id' => $group->id, 'sort_order' => $i]);
            }

            return $group;
        });

        return new JsonResponse(['data' => $group], $id === null ? 201 : 200);
    }
}
