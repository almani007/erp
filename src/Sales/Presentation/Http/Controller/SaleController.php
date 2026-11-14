<?php

declare(strict_types=1);

namespace Sales\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Organization\Domain\Contract\BranchContext;
use Sales\Application\PriceCart;
use Sales\Application\RecordSale;
use Sales\Application\VoidSale;
use Sales\Domain\Enum\PaymentMethod;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Shared\Infrastructure\Validation\TenantRule;

final class SaleController
{
    /** Price a cart without committing anything (the POS totals panel). */
    public function quote(Request $request, PriceCart $cart): JsonResponse
    {
        $data = $request->validate($this->lineRules());

        $priced = $cart->price($data['lines'], $request->user()->can('sales.price.override'));

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
        $sale = Sale::with(['items', 'tenders', 'customer:id,name,customer_code'])->findOrFail($saleId);
        abort_unless($branches->canAccess($sale->branch_id), 404);

        return new JsonResponse(['data' => $sale]);
    }

    public function void(Request $request, string $saleId, VoidSale $action, BranchContext $branches): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        $sale = Sale::findOrFail($saleId);
        abort_unless($branches->canAccess($sale->branch_id), 404);

        return new JsonResponse(['data' => $action->execute($sale->id, $data['reason'], $request->user()->id)]);
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
        ];
    }
}
