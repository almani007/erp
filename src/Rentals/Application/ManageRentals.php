<?php

declare(strict_types=1);

namespace Rentals\Application;

use Carbon\CarbonImmutable;
use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Inventory\Domain\Enum\MovementType;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Domain\StockPosting;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Rentals\Infrastructure\Persistence\Eloquent\RentalContract;
use Sales\Application\RecordSale;
use Sales\Domain\SaleOrigin;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Rental and hire: the item goes out (and out of available stock if it is
 * stocked), a security deposit is held, rent is invoiced on the customer's
 * account every period automatically, and on return the item comes back
 * and the deposit is refunded less any deduction for damage.
 *
 * Deposit received   Dr Cash/Bank            Cr Customer deposits held
 * Deposit refunded   Dr Customer deposits    Cr Cash/Bank, Other income (deduction)
 * Item out / back    the quantity leaves and returns to stock at the same cost;
 *                    no journal, because the item is lent, not sold.
 */
final class ManageRentals
{
    public function __construct(
        private readonly RecordSale $recordSale,
        private readonly StockLedger $stock,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function start(array $data): RentalContract
    {
        return DB::transaction(function () use ($data): RentalContract {
            $warehouse = Warehouse::query()->where('status', 'active')->findOrFail($data['warehouse_id']);
            $deposit = Decimal::round((string) ($data['deposit_amount'] ?? '0'), 2);

            $contract = RentalContract::query()->create([
                'company_id' => $warehouse->company_id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'contract_no' => $this->numbers->next($this->tenant->requireId(), 'rental_contract', $warehouse->company_id, 'RNT'),
                'customer_id' => $data['customer_id'], 'rent_product_id' => $data['rent_product_id'],
                'item_product_id' => $data['item_product_id'] ?? null, 'item_description' => $data['item_description'],
                'serial_no' => $data['serial_no'] ?? null, 'quantity' => $data['quantity'] ?? 1,
                'start_date' => $data['start_date'], 'end_date' => $data['end_date'] ?? null,
                'billing_frequency' => $data['billing_frequency'], 'rent_amount' => Decimal::round((string) $data['rent_amount'], 2),
                'next_bill_date' => $data['start_date'], 'payment_days' => $data['payment_days'] ?? 0,
                'deposit_amount' => $deposit, 'deposit_method' => Decimal::isPositive($deposit) ? ($data['deposit_method'] ?? 'cash') : null,
                'status' => 'active', 'notes' => $data['notes'] ?? null, 'created_by' => $this->userId(),
            ]);

            // A stocked item leaves the shelf while it is rented out.
            if ($contract->item_product_id !== null) {
                // The quantity leaves available stock; no journal, because the item still belongs to the business.
                $this->stock->issue(new StockPosting(
                    $contract->company_id, $warehouse->id, $contract->item_product_id, (string) $contract->quantity,
                    MovementType::RentalOut, 'rental_contract', $contract->id, "Rented out on {$contract->contract_no}",
                ));
            }

            if (Decimal::isPositive($deposit)) {
                $this->postJournal->execute(new JournalDraft(
                    companyId: $contract->company_id, postingDate: now()->toDateString(), sourceType: 'rental_deposit', sourceId: $contract->id,
                    description: "Deposit on {$contract->contract_no}",
                    lines: [
                        JournalLineDraft::debit($this->accounts->id($contract->company_id, $contract->deposit_method === 'bank' ? 'bank' : 'cash'), $deposit, $contract->branch_id),
                        JournalLineDraft::credit($this->accounts->id($contract->company_id, 'customer_deposits'), $deposit, $contract->branch_id, null, 'customer', $contract->customer_id),
                    ],
                ));
            }

            $this->audit->record('rentals.contract.started', 'rental_contract', $contract->id, null, ['no' => $contract->contract_no]);

            return $contract;
        });
    }

    /**
     * Raise every rent invoice that has fallen due. Runs nightly for all
     * tenants (`$tenantId` null) and on demand for one.
     *
     * @return array{invoiced: int, failed: int}
     */
    public function billDue(?string $tenantId, ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $done = $failed = 0;

        $due = DB::table('rental_contracts')->where('status', 'active')->where('next_bill_date', '<=', $today)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->get(['id', 'tenant_id']);

        foreach ($due as $row) {
            $this->tenant->set($row->tenant_id);
            for ($i = 0; $i < 31; $i++) {
                try {
                    $billed = DB::transaction(fn (): bool => $this->billOne($row->id, $today));
                } catch (\Throwable $e) {
                    RentalContract::query()->whereKey($row->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
                    $failed++;
                    break;
                }
                if (! $billed) {
                    break;
                }
                $done++;
            }
        }

        return ['invoiced' => $done, 'failed' => $failed];
    }

    private function billOne(string $id, string $today): bool
    {
        $c = RentalContract::query()->lockForUpdate()->findOrFail($id);
        $date = $c->next_bill_date->toDateString();
        if ($c->status !== 'active' || $date > $today || ($c->end_date !== null && $date > $c->end_date->toDateString())) {
            return false;
        }

        $next = self::advance(CarbonImmutable::parse($date), $c->billing_frequency);
        $periodEnd = $next->subDay()->toDateString();

        $sale = $this->recordSale->invoice(
            new SaleOrigin(
                companyId: $c->company_id, branchId: $c->branch_id, warehouseId: $c->warehouse_id, userId: $c->created_by,
                channel: SaleOrigin::INVOICE, dueDate: CarbonImmutable::parse($date)->addDays((int) $c->payment_days)->toDateString(),
                reference: $c->contract_no, notes: "Rent {$date} to {$periodEnd}: {$c->item_description}",
            ),
            [['product_id' => $c->rent_product_id, 'quantity' => '1', 'unit_price' => (string) $c->rent_amount, 'agreed' => true]],
            $c->customer_id, null, '0', 'cash', null,
        );
        DB::table('sales')->where('id', $sale->id)->update(['rental_contract_id' => $c->id]);

        $c->update(['next_bill_date' => $next->toDateString(), 'invoices_count' => $c->invoices_count + 1, 'last_error' => null]);

        return true;
    }

    public function returnItem(string $id, string $date, string $deduction, ?string $refundMethod, ?string $notes): RentalContract
    {
        return DB::transaction(function () use ($id, $date, $deduction, $refundMethod, $notes): RentalContract {
            $c = RentalContract::query()->lockForUpdate()->findOrFail($id);
            if ($c->status !== 'active') {
                throw new \DomainException("{$c->contract_no} is already {$c->status}.");
            }

            // Bill up to the return date first, so nothing is left unbilled.
            $this->billDue($c->tenant_id, $date);
            $c->refresh();

            if ($c->item_product_id !== null) {
                $out = DB::table('stock_movements')->where('source_type', 'rental_contract')->where('source_id', $c->id)
                    ->where('movement_type', MovementType::RentalOut->value)->first(['unit_cost']);
                $cost = (string) ($out->unit_cost ?? '0');
                $this->stock->receive(new StockPosting(
                    $c->company_id, $c->warehouse_id, $c->item_product_id, (string) $c->quantity,
                    MovementType::RentalReturn, 'rental_contract', $c->id, "Returned on {$c->contract_no}",
                ), $cost);
            }

            $deposit = Decimal::round((string) $c->deposit_amount, 2);
            $deduct = Decimal::round($deduction, 2);
            if (Decimal::cmp($deduct, $deposit) > 0) {
                throw new \DomainException('The deduction cannot be more than the deposit held.');
            }
            $refund = Decimal::sub($deposit, $deduct, 2);
            if (Decimal::isPositive($deposit)) {
                $lines = [JournalLineDraft::debit($this->accounts->id($c->company_id, 'customer_deposits'), $deposit, $c->branch_id, null, 'customer', $c->customer_id)];
                if (Decimal::isPositive($refund)) {
                    $lines[] = JournalLineDraft::credit($this->accounts->id($c->company_id, $refundMethod === 'bank' ? 'bank' : 'cash'), $refund, $c->branch_id, 'Deposit refunded');
                }
                if (Decimal::isPositive($deduct)) {
                    $lines[] = JournalLineDraft::credit($this->accounts->id($c->company_id, 'sales_revenue'), $deduct, $c->branch_id, 'Damage / late deduction from deposit');
                }
                $this->postJournal->execute(new JournalDraft(
                    companyId: $c->company_id, postingDate: $date, sourceType: 'rental_return', sourceId: $c->id,
                    description: "Return on {$c->contract_no}", lines: $lines,
                ));
            }

            $c->update(['status' => 'returned', 'returned_on' => $date, 'deposit_refunded' => $refund, 'deposit_deducted' => $deduct, 'return_notes' => $notes]);
            $this->audit->record('rentals.contract.returned', 'rental_contract', $c->id, null, ['no' => $c->contract_no, 'deduction' => $deduct]);

            return $c;
        });
    }

    public static function advance(CarbonImmutable $date, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'daily' => $date->addDay(),
            'weekly' => $date->addWeek(),
            default => $date->addMonthNoOverflow(),
        };
    }

    private function userId(): string
    {
        return (string) $this->auth->guard('sanctum')->id();
    }
}
