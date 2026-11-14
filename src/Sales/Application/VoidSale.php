<?php

declare(strict_types=1);

namespace Sales\Application;

use Production\Application\RecipeConsumption;
use Finance\Application\ReverseJournalEntry;
use Illuminate\Support\Facades\DB;
use Inventory\Domain\Enum\MovementType;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Domain\StockPosting;
use Sales\Domain\Enum\PaymentMethod;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Sales\Infrastructure\Persistence\Eloquent\PosShift;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\EventOutbox;
use Shared\Domain\Support\Decimal;

/**
 * Voids a sale made in a shift that is still open: the goods go back into
 * stock at the cost they left at, the journal is reversed and the customer's
 * credit is released. The sale row stays, marked voided — nothing is deleted.
 *
 * Once the shift is closed its cash has been counted, so later corrections
 * need a return/refund document (not built yet).
 */
final class VoidSale
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly AuditTrail $audit,
        private readonly EventOutbox $outbox,
        private readonly LoyaltyProgram $loyalty,
    ) {}

    public function execute(string $saleId, string $reason, string $actorId): Sale
    {
        return DB::transaction(function () use ($saleId, $reason, $actorId): Sale {
            $sale = Sale::query()->lockForUpdate()->findOrFail($saleId);

            if ($sale->status !== Sale::COMPLETED) {
                throw new \DomainException("Sale {$sale->sale_no} is already voided.");
            }

            if ($sale->returns()->exists()) {
                throw new \DomainException("Sale {$sale->sale_no} has returns against it and cannot be voided.");
            }

            if ($sale->shift_id !== null) {
                $shift = PosShift::query()->lockForUpdate()->findOrFail($sale->shift_id);
                if (! $shift->isOpen()) {
                    throw new \DomainException("Sale {$sale->sale_no} belongs to a closed shift and can no longer be voided.");
                }
            } elseif (DB::table('customer_payment_allocations')->where('sale_id', $sale->id)->exists()) {
                throw new \DomainException("Invoice {$sale->sale_no} has payments applied to it. Record a return instead.");
            }

            // An invoice from a sales order: those quantities can be invoiced again.
            if ($sale->sales_order_id !== null) {
                app(\Sales\Application\ManageSalesOrders::class)->releaseInvoice($sale);
            }

            foreach ($sale->items as $item) {
                $tracked = DB::table('stock_movements')
                    ->where('source_type', 'sale')->where('source_id', $sale->id)->where('product_id', $item->product_id)
                    ->exists();

                if ($tracked) {
                    $this->stock->receive(new StockPosting(
                        $sale->company_id, $sale->warehouse_id, $item->product_id,
                        Decimal::mul((string) $item->quantity, (string) $item->unit_factor, Decimal::QTY_SCALE), // packs → base units
                        MovementType::SaleVoid, 'sale', $sale->id, 'void: '.$reason,
                        lotsFromSourceType: 'sale', lotsFromSourceId: $sale->id,
                    ), (string) $item->cost_snapshot);
                }
            }

            // Ingredients of recipe items (dishes, drinks) go back too.
            app(RecipeConsumption::class)->restoreForVoid($sale->company_id, $sale->warehouse_id, $sale->id, $reason);

            $onCredit = (string) $sale->tenders->where('method', PaymentMethod::Credit->value)
                ->reduce(static fn (string $sum, $t): string => Decimal::add($sum, (string) $t->amount), '0');

            if ($sale->customer_id !== null && Decimal::isPositive($onCredit)) {
                $customer = Customer::query()->lockForUpdate()->findOrFail($sale->customer_id);
                $customer->forceFill(['balance' => Decimal::sub((string) $customer->balance, $onCredit)])->save();
            }

            // The units are back on the shelf, so their serials can be sold again.
            DB::table('sold_serials')->where('sale_id', $sale->id)->delete();

            // Give back the points spent and take back the points earned.
            $pointsBack = (int) $sale->points_redeemed - (int) $sale->points_earned;
            if ($sale->customer_id !== null && $pointsBack !== 0) {
                $member = Customer::query()->lockForUpdate()->findOrFail($sale->customer_id);
                $this->loyalty->move($member, $pointsBack, 'reverse', 'sale', $sale->id, 'void');
            }

            if ($sale->journal_entry_id !== null) {
                $this->reverseJournal->execute($sale->journal_entry_id, "void of sale {$sale->sale_no}", now()->toDateString());
            }

            $sale->update([
                'status' => Sale::VOIDED,
                'balance_due' => '0',
                'voided_at' => now(),
                'voided_by' => $actorId,
                'void_reason' => $reason,
            ]);

            $this->audit->record('sales.sale.voided', 'sale', $sale->id,
                ['status' => Sale::COMPLETED], ['status' => Sale::VOIDED, 'sale_no' => $sale->sale_no], $reason);
            $this->outbox->record('sales.sale.voided', 'sale', $sale->id, ['sale_no' => $sale->sale_no]);

            return $sale->load(['items', 'tenders', 'customer:id,name,customer_code']);
        });
    }
}
