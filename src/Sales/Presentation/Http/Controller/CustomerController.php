<?php

declare(strict_types=1);

namespace Sales\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Domain\Contract\BranchContext;
use Sales\Application\LoyaltyProgram;
use Sales\Application\ReceiveCustomerPayment;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Sales\Infrastructure\Persistence\Eloquent\CustomerPayment;
use Shared\Infrastructure\Validation\TenantRule;
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

            $customer = new Customer(array_diff_key($data, ['custom' => true]) + [
                'customer_code' => $numbers->next($tenantId, 'customer', $tenantId, 'CUS'),
                'status' => 'active',
            ]);
            $customer->mergeCustom($data['custom'] ?? null);
            $customer->save();

            return $customer;
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
            $customer->fill(array_diff_key($data, ['custom' => true]));
            $customer->mergeCustom($data['custom'] ?? null);
            $customer->save();

            if (isset($data['credit_limit']) && bccomp($before, (string) $data['credit_limit'], 4) !== 0) {
                $audit->record('sales.customer.credit_limit_changed', 'customer', $customer->id,
                    ['credit_limit' => $before], ['credit_limit' => (string) $data['credit_limit']]);
            }
        });

        return new JsonResponse(['data' => $customer->fresh()]);
    }

    /** Recent credit activity for one customer: payments received and what is still owed. */
    public function payments(string $customerId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);

        return new JsonResponse(['data' => [
            'customer' => $customer,
            'payments' => CustomerPayment::query()->where('customer_id', $customer->id)->orderByDesc('received_at')->limit(100)->get(),
            'loyalty' => DB::table('loyalty_transactions')->where('customer_id', $customer->id)->orderByDesc('created_at')->limit(50)
                ->get(['id', 'type', 'points', 'source_type', 'note', 'created_at']),
        ]]);
    }

    /** Manual points correction (goodwill, migration from an old scheme). Audited. */
    public function adjustLoyalty(Request $request, string $customerId, LoyaltyProgram $loyalty, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $customer = DB::transaction(function () use ($customerId, $data, $loyalty, $audit): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $before = $customer->loyalty_points;
            $loyalty->move($customer, (int) $data['points'], 'adjust', null, null, $data['note']);
            $audit->record('sales.customer.loyalty_adjusted', 'customer', $customer->id, ['points' => $before], ['points' => $customer->loyalty_points], $data['note']);

            return $customer;
        });

        return new JsonResponse(['data' => $customer]);
    }

    public function receivePayment(Request $request, string $customerId, ReceiveCustomerPayment $action, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'method' => ['required', 'in:cash,bank'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $payment = $action->execute(
            $customerId, $data['company_id'], $branches->activeBranchId(), $data['method'],
            (string) $data['amount'], $data['reference'] ?? null, $request->user()->id,
        );

        return new JsonResponse(['data' => $payment], 201);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'tax_no' => ['nullable', 'string', 'max:80'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            ...Customer::customFieldRules(),
            'price_tier' => ['sometimes', 'in:retail,wholesale'],
        ];
    }
}
