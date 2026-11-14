<?php

declare(strict_types=1);

namespace Sales\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Organization\Domain\Contract\BranchContext;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Sales\Application\ManageSalesOrders;
use Sales\Infrastructure\Persistence\Eloquent\SalesOrder;
use Shared\Infrastructure\Validation\TenantRule;

/** Quotations and sales orders (`/sales-documents?type=quotation|order`). */
final class SalesOrderController
{
    public function index(Request $request, BranchContext $branches): JsonResponse
    {
        $type = $request->validate(['type' => ['required', 'in:quotation,order']])['type'];
        $allowed = $branches->allowedBranchIds();

        $rows = SalesOrder::query()
            ->with('customer:id,name,customer_code')
            ->where('doc_type', $type)
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->orderByDesc('doc_date')->orderByDesc('created_at')
            ->limit(2000)->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function show(string $id, BranchContext $branches): JsonResponse
    {
        $doc = SalesOrder::query()
            ->with(['lines.product:id,sku,name,track_serial', 'customer:id,name,customer_code,phone,email,tax_no,address,payment_days,credit_limit,balance',
                'invoices:id,sales_order_id,sale_no,sold_at,grand_total,balance_due,due_date,status'])
            ->findOrFail($id);
        abort_unless($branches->canAccess($doc->branch_id), 404);

        return new JsonResponse(['data' => $doc]);
    }

    public function store(Request $request, ManageSalesOrders $action, BranchContext $branches): JsonResponse
    {
        $data = $this->validated($request, $branches);

        return new JsonResponse(['data' => $action->save($data, $request->user()->can('sales.price.override'))], 201);
    }

    public function update(Request $request, string $id, ManageSalesOrders $action, BranchContext $branches): JsonResponse
    {
        $doc = SalesOrder::query()->findOrFail($id);
        abort_unless($branches->canAccess($doc->branch_id), 404);
        $data = $this->validated($request, $branches) + ['doc_type' => $doc->doc_type];

        return new JsonResponse(['data' => $action->save($data, $request->user()->can('sales.price.override'), $doc)]);
    }

    public function quotationStatus(Request $request, string $id, ManageSalesOrders $action): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'in:sent,accepted,rejected']])['status'];

        return new JsonResponse(['data' => $action->setQuotationStatus($id, $status)]);
    }

    public function convert(string $id, ManageSalesOrders $action): JsonResponse
    {
        return new JsonResponse(['data' => $action->convert($id)], 201);
    }

    public function cancel(string $id, ManageSalesOrders $action): JsonResponse
    {
        return new JsonResponse(['data' => $action->cancel($id)]);
    }

    public function invoice(Request $request, string $id, ManageSalesOrders $action, BranchContext $branches): JsonResponse
    {
        $order = SalesOrder::query()->findOrFail($id);
        abort_unless($branches->canAccess($order->branch_id), 404);

        $data = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'decimal:0,6'],
            'lines.*.serials' => ['sometimes', 'array'],
            'lines.*.serials.*' => ['string', 'max:80'],
            'paid_now' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'paid_by' => ['required_with:paid_now', 'in:cash,card,bank_transfer,wallet'],
        ]);

        $quantities = isset($data['lines']) ? collect($data['lines'])->mapWithKeys(fn ($l) => [$l['line_id'] => (string) $l['quantity']])->all() : null;
        $serials = collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [$l['line_id'] => $l['serials'] ?? []])->all();

        // Value of what is being invoiced, at the order's prices.
        $value = 0.0;
        foreach ($order->lines as $line) {
            $q = $quantities === null ? (float) $line->quantity - (float) $line->invoiced_quantity : (float) ($quantities[$line->id] ?? 0);
            $value += $q * (float) $line->line_total / max((float) $line->quantity, 0.000001);
        }
        $pending = app(\Workflow\Application\Approvals::class)->gate('sales_invoice', number_format($value, 2, '.', ''),
            "Invoice {$order->doc_no} to ".($order->customer?->name ?? 'customer'),
            ['order_id' => $order->id, 'quantities' => $quantities, 'paid_now' => (string) ($data['paid_now'] ?? '0'), 'paid_by' => $data['paid_by'] ?? 'cash', 'serials' => $serials],
            $order->branch_id);
        if ($pending !== null) {
            return new JsonResponse(['data' => ['status' => 'pending_approval', 'approval_request' => $pending]], 202);
        }

        $sale = $action->invoice($order->id, $quantities, (string) ($data['paid_now'] ?? '0'), $data['paid_by'] ?? 'cash', $serials, $request->header('Idempotency-Key'));

        return new JsonResponse(['data' => $sale], 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, BranchContext $branches): array
    {
        $data = $request->validate([
            'doc_type' => ['sometimes', 'in:quotation,order'],
            'warehouse_id' => ['required', 'uuid', TenantRule::exists('warehouses')],
            'customer_id' => ['required', 'uuid', TenantRule::exists('customers')],
            'doc_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:doc_date'],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'price_tier' => ['nullable', 'in:retail,wholesale'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
        ]);

        $branchId = Warehouse::query()->whereKey($data['warehouse_id'])->value('branch_id');
        abort_unless($branchId !== null && $branches->canAccess($branchId), 403, 'That warehouse is in a branch you cannot use.');

        $data['doc_type'] ??= 'order';
        foreach ($data['lines'] as $i => $line) {
            $data['lines'][$i]['quantity'] = (string) $line['quantity'];
            foreach (['unit_price', 'discount'] as $k) {
                if (isset($line[$k])) {
                    $data['lines'][$i][$k] = (string) $line[$k];
                }
            }
        }

        return $data;
    }
}
