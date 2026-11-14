<?php

declare(strict_types=1);

namespace Sales\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Organization\Domain\Contract\BranchContext;
use Sales\Application\PriceCart;
use Sales\Application\RecordSale;
use Sales\Application\RecordSaleReturn;
use Sales\Application\VoidSale;
use Sales\Domain\Enum\PaymentMethod;
use Sales\Infrastructure\Persistence\Eloquent\PosShift;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Sales\Infrastructure\Persistence\Eloquent\SalesReturn;
use Shared\Domain\Support\Decimal;
use Shared\Infrastructure\Validation\TenantRule;

final class SaleController
{
    /** Price a cart without committing anything (the POS totals panel). */
    public function quote(Request $request, PriceCart $cart, BranchContext $branches): JsonResponse
    {
        $data = $request->validate($this->lineRules());

        // Price for the branch the cashier is selling in; outside a shift, the selected branch.
        $branchId = PosShift::query()->where('cashier_id', $request->user()->id)->where('status', 'open')->value('branch_id')
            ?? $branches->activeBranchId();

        $priced = $cart->price($data['lines'], $request->user()->can('sales.price.override'), $branchId, $data['coupon_code'] ?? null);

        $priced['coupon'] = $priced['coupon']?->code;
        $priced['lines'] = array_map(static fn (array $line): array => [
            'product_id' => $line['product']->id,
            'sku' => $line['product']->sku,
            'name' => $line['product']->name,
        ] + array_diff_key($line, ['product' => true]), $priced['lines']);

        return new JsonResponse(['data' => $priced]);
    }

    public function store(Request $request, RecordSale $action): JsonResponse
    {
        $data = $request->validate($this->lineRules() + [
            'customer_id' => ['nullable', 'uuid', TenantRule::exists('customers')],
            'tenders' => ['required', 'array', 'min:1', 'max:10'],
            'tenders.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'tenders.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tenders.*.reference' => ['nullable', 'string', 'max:120'],
        ]);

        $sale = $action->execute(
            cashierId: $request->user()->id,
            lines: $data['lines'],
            tenders: $data['tenders'],
            customerId: $data['customer_id'] ?? null,
            mayOverridePrice: $request->user()->can('sales.price.override'),
            idempotencyKey: $request->header('Idempotency-Key'),
            couponCode: $data['coupon_code'] ?? null,
        );

        return new JsonResponse(['data' => $sale], 201);
    }

    public function index(Request $request, BranchContext $branches): JsonResponse
    {
        $q = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:completed,voided'],
        ]);
        $allowed = $branches->allowedBranchIds();

        $sales = Sale::query()
            ->with('customer:id,name')
            ->when($allowed !== null, fn ($b) => $b->whereIn('branch_id', $allowed))
            ->when($q['branch_id'] ?? null, fn ($b, $id) => $b->where('branch_id', $id))
            ->when($q['status'] ?? null, fn ($b, $s) => $b->where('status', $s))
            ->when($q['from'] ?? null, fn ($b, $d) => $b->where('sold_at', '>=', $d))
            ->when($q['to'] ?? null, fn ($b, $d) => $b->where('sold_at', '<=', $d))
            ->orderByDesc('sold_at')
            ->limit(500)
            ->get();

        return new JsonResponse(['data' => $sales]);
    }

    public function show(string $saleId, BranchContext $branches): JsonResponse
    {
        $sale = Sale::with(['items', 'tenders', 'customer:id,name,customer_code', 'returns.items'])->findOrFail($saleId);
        abort_unless($branches->canAccess($sale->branch_id), 404);

        // How much of each line has already come back, for the return form.
        $returned = $sale->returns->flatMap->items->groupBy('sale_item_id')
            ->map(static fn ($items): string => Decimal::sum($items, 'quantity', Decimal::QTY_SCALE));
        $sale->items->each(static fn ($item) => $item->setAttribute('returned_quantity', $returned[$item->id] ?? '0'));

        return new JsonResponse(['data' => $sale]);
    }

    public function void(Request $request, string $saleId, VoidSale $action, BranchContext $branches): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        $sale = Sale::findOrFail($saleId);
        abort_unless($branches->canAccess($sale->branch_id), 404);

        return new JsonResponse(['data' => $action->execute($sale->id, $data['reason'], $request->user()->id)]);
    }

    public function storeReturn(Request $request, string $saleId, RecordSaleReturn $action, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'refund_method' => ['required', Rule::in(RecordSaleReturn::REFUND_METHODS)],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.sale_item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'lines.*.restock' => ['sometimes', 'boolean'],
        ]);

        $sale = Sale::findOrFail($saleId);
        abort_unless($branches->canAccess($sale->branch_id), 404);

        $return = $action->execute($sale->id, $data['lines'], $data['refund_method'], $data['reason'], $request->user()->id);

        return new JsonResponse(['data' => $return], 201);
    }

    public function returns(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        $rows = SalesReturn::query()
            ->with('sale:id,sale_no')
            ->when($allowed !== null, fn ($b) => $b->whereIn('branch_id', $allowed))
            ->orderByDesc('returned_at')
            ->limit(500)
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    /** @return array<string, mixed> */
    private function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
