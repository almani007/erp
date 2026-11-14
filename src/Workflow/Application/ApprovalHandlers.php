<?php

declare(strict_types=1);

namespace Workflow\Application;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\Auth;
use Inventory\Application\PostStockAdjustment;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use People\Application\GiveAdvance;
use People\Infrastructure\Persistence\Eloquent\Employee;
use Purchasing\Application\PaySupplier;
use Sales\Application\ManageSalesOrders;

/**
 * Carries out an approved request exactly as it was asked for, signed in as
 * the requester — so documents show who asked, and the approvals show who agreed.
 */
final class ApprovalHandlers
{
    /**
     * @param  array<string, mixed>  $p
     * @return array{type: string, id: string, label: string}
     */
    public function run(string $type, array $p, string $requesterId): array
    {
        $guard = Auth::guard('sanctum');
        $approver = $guard->user();
        $guard->setUser(User::query()->findOrFail($requesterId));

        try {
            return match ($type) {
                'supplier_payment' => (function () use ($p, $requesterId): array {
                    $payment = app(PaySupplier::class)->execute($p['supplier_id'], $p['company_id'], $p['method'], (string) $p['amount'],
                        $p['reference'] ?? null, $requesterId, $p['allocations'] ?? null, null, $p['currency'] ?? null, $p['fx_rate'] ?? null);

                    return ['type' => 'supplier_payment', 'id' => $payment->id, 'label' => "Payment {$payment->payment_no}"];
                })(),
                'stock_adjustment' => (function () use ($p): array {
                    $adjustment = app(PostStockAdjustment::class)->execute(Warehouse::query()->findOrFail($p['warehouse_id']), $p['reason_code'], $p['notes'] ?? null, $p['lines']);

                    return ['type' => 'stock_adjustment', 'id' => $adjustment->id, 'label' => 'Adjustment '.($adjustment->adjustment_no ?? '')];
                })(),
                'sales_invoice' => (function () use ($p): array {
                    $sale = app(ManageSalesOrders::class)->invoice($p['order_id'], $p['quantities'] ?? null, (string) ($p['paid_now'] ?? '0'), $p['paid_by'] ?? 'cash', $p['serials'] ?? [], null);

                    return ['type' => 'sale', 'id' => $sale->id, 'label' => "Invoice {$sale->sale_no}"];
                })(),
                'employee_advance' => (function () use ($p): array {
                    $advance = app(GiveAdvance::class)->execute(Employee::query()->findOrFail($p['employee_id']), $p['data']);

                    return ['type' => 'employee_advance', 'id' => $advance->id, 'label' => "Advance {$advance->advance_no}"];
                })(),
                default => throw new \DomainException("No action is registered for {$type}."),
            };
        } finally {
            if ($approver !== null) {
                $guard->setUser($approver);
            }
        }
    }
}
