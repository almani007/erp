<?php

declare(strict_types=1);

namespace Purchasing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Purchasing\Application\PaySupplier;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierPayment;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Infrastructure\Validation\TenantRule;

final class SupplierController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => Supplier::query()->orderBy('name')->limit(2000)->get()]);
    }

    public function store(Request $request, DocumentNumbers $numbers, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate($this->rules() + ['name' => ['required', 'string', 'max:150']]);

        $supplier = DB::transaction(function () use ($data, $numbers, $tenant): Supplier {
            $tenantId = $tenant->requireId();

            $supplier = new Supplier(array_diff_key($data, ['custom' => true]) + [
                'supplier_code' => $numbers->next($tenantId, 'supplier', $tenantId, 'SUP'),
                'status' => 'active',
            ]);
            $supplier->mergeCustom($data['custom'] ?? null);
            $supplier->save();

            return $supplier;
        });

        return new JsonResponse(['data' => $supplier->fresh()], 201);
    }

    public function update(Request $request, string $supplierId): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplierId);
        $data = $request->validate($this->rules() + [
            'name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $supplier->fill(array_diff_key($data, ['custom' => true]));
        $supplier->mergeCustom($data['custom'] ?? null);
        $supplier->save();

        return new JsonResponse(['data' => $supplier->fresh()]);
    }

    public function payments(string $supplierId): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplierId);

        return new JsonResponse(['data' => [
            'supplier' => $supplier,
            'payments' => SupplierPayment::query()->where('supplier_id', $supplier->id)->orderByDesc('paid_at')->limit(100)->get(),
        ]]);
    }

    public function pay(Request $request, string $supplierId, PaySupplier $action): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'method' => ['required', 'in:cash,bank'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:120'],
            'allocations' => ['sometimes', 'array', 'max:200'],
            'allocations.*.supplier_bill_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['nullable', 'string', 'size:3'],      // pay in a foreign currency: amount and allocations are in it
            'fx_rate' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8'],
        ]);
        $currency = isset($data['currency']) ? strtoupper($data['currency']) : null;
        $fxRate = isset($data['fx_rate']) ? (string) $data['fx_rate'] : null;

        $allocations = isset($data['allocations'])
            ? collect($data['allocations'])->mapWithKeys(fn (array $a): array => [$a['supplier_bill_id'] => (string) $a['amount']])->all()
            : null;

        $supplier = Supplier::query()->findOrFail($supplierId);
        // Approval limits are in base currency.
        $rates = app(\Finance\Application\ExchangeRates::class);
        $baseAmount = $currency !== null && $rates->isForeign($data['company_id'], $currency)
            ? \Finance\Application\ExchangeRates::toBase((string) $data['amount'], $fxRate ?? $rates->rate($data['company_id'], $currency, now()->toDateString()))
            : (string) $data['amount'];
        $pending = app(\Workflow\Application\Approvals::class)->gate('supplier_payment', $baseAmount,
            "Pay {$supplier->name} ".($currency ? "{$currency} " : '').number_format((float) $data['amount'], 2)." by {$data['method']}",
            ['supplier_id' => $supplierId, 'company_id' => $data['company_id'], 'method' => $data['method'], 'amount' => (string) $data['amount'],
                'reference' => $data['reference'] ?? null, 'allocations' => $allocations, 'currency' => $currency, 'fx_rate' => $fxRate]);
        if ($pending !== null) {
            return new JsonResponse(['data' => ['status' => 'pending_approval', 'approval_request' => $pending]], 202);
        }

        $payment = $action->execute($supplierId, $data['company_id'], $data['method'], (string) $data['amount'], $data['reference'] ?? null, $request->user()->id, $allocations, null, $currency, $fxRate);

        return new JsonResponse(['data' => $payment], 201);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'tax_no' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:300'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'currency' => ['nullable', 'string', 'size:3', \Shared\Infrastructure\Validation\TenantRule::exists('currencies', 'code')],
            ...Supplier::customFieldRules(),
        ];
    }
}
