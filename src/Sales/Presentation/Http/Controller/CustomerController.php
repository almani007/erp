<?php

declare(strict_types=1);

namespace Sales\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;

final class CustomerController
{
    public function index(Request $request): JsonResponse
    {
        $term = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        $customers = Customer::query()
            ->when($term, function ($b, string $term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $b->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('phone', 'ilike', $like)->orWhere('customer_code', 'ilike', $like));
            })
            ->orderBy('name')
            ->limit(1000)
            ->get();

        return new JsonResponse(['data' => $customers]);
    }

    public function store(Request $request, DocumentNumbers $numbers, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate($this->rules() + ['name' => ['required', 'string', 'max:150']]);

        $customer = DB::transaction(function () use ($data, $numbers, $tenant): Customer {
            $tenantId = $tenant->requireId();

            return Customer::query()->create($data + [
                'customer_code' => $numbers->next($tenantId, 'customer', $tenantId, 'CUS'),
                'status' => 'active',
            ]);
        });

        return new JsonResponse(['data' => $customer->fresh()], 201);
    }

    public function update(Request $request, string $customerId, AuditTrail $audit): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        $data = $request->validate($this->rules() + [
            'name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        DB::transaction(function () use ($customer, $data, $audit): void {
            $before = (string) $customer->credit_limit;
            $customer->update($data);

            if (isset($data['credit_limit']) && bccomp($before, (string) $data['credit_limit'], 4) !== 0) {
                $audit->record('sales.customer.credit_limit_changed', 'customer', $customer->id,
                    ['credit_limit' => $before], ['credit_limit' => (string) $data['credit_limit']]);
            }
        });

        return new JsonResponse(['data' => $customer->fresh()]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'tax_no' => ['nullable', 'string', 'max:80'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }
}
