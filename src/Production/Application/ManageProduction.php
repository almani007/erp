<?php

declare(strict_types=1);

namespace Production\Application;

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
use Production\Infrastructure\Persistence\Eloquent\ProductionOrder;
use Production\Infrastructure\Persistence\Eloquent\Recipe;
use Production\Infrastructure\Persistence\Eloquent\WorkCentre;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Plan → complete (or cancel). Completing takes the materials out of the
 * warehouse at their average cost and puts the finished quantity in at
 * (materials + extra cost) / quantity produced.
 *
 * Materials and finished goods sit in the same inventory account, so only
 * the extra cost (labour, power, overheads) is journalled: debit inventory,
 * credit "production costs applied" — which offsets the real wage and
 * utility expenses booked through vouchers. Work steps (routing) are
 * costed automatically at their work centres' hourly rates.
 */
final class ManageProduction
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * With `$withSubAssemblies`, any material that has its own production
     * recipe and is short in the warehouse gets a production order of its
     * own for the shortfall (and so on down, up to five levels). Make those
     * first: completing them puts the sub-assemblies in stock for this one.
     */
    public function plan(Recipe $recipe, Warehouse $warehouse, string $quantity, ?string $notes, bool $withSubAssemblies = false, ?string $parentId = null, int $depth = 0): ProductionOrder
    {
        if ($recipe->status !== 'active' || $recipe->consume_on_sale) {
            throw new \DomainException('Only an active production recipe can be planned.');
        }
        if (! Decimal::isPositive($quantity)) {
            throw new \DomainException('Plan a positive quantity.');
        }

        return DB::transaction(function () use ($recipe, $warehouse, $quantity, $notes): ProductionOrder {
            $order = ProductionOrder::query()->create([
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                'recipe_id' => $recipe->id,
                'product_id' => $recipe->product_id,
                'order_no' => $this->numbers->next($this->tenant->requireId(), 'production_order', $warehouse->company_id, 'PRD'),
                'planned_quantity' => $quantity,
                'status' => ProductionOrder::PLANNED,
                'notes' => $notes,
                'parent_id' => $parentId,
                'created_by' => $this->auth->guard('sanctum')->id(),
            ]);

            foreach (SaveRecipe::requirements($recipe->load('items'), $quantity) as $productId => $required) {
                $order->items()->create(['product_id' => $productId, 'required_quantity' => $required]);

                if (! $withSubAssemblies || $depth >= 5) {
                    continue;
                }
                $subRecipe = Recipe::query()->where('product_id', $productId)->where('status', 'active')->where('consume_on_sale', false)->first();
                if ($subRecipe === null) {
                    continue;
                }
                $onHand = (string) (DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('product_id', $productId)->value('on_hand') ?? '0');
                // Already planned but not yet made counts as coming.
                $coming = (string) (ProductionOrder::query()->where('product_id', $productId)->where('warehouse_id', $warehouse->id)
                    ->where('status', ProductionOrder::PLANNED)->sum('planned_quantity'));
                $short = Decimal::sub($required, Decimal::add($onHand, $coming, Decimal::QTY_SCALE), Decimal::QTY_SCALE);
                if (Decimal::isPositive($short)) {
                    $this->plan($subRecipe, $warehouse, $short, "Sub-assembly for {$order->order_no}", true, $order->id, $depth + 1);
                }
            }

            $this->audit->record('production.order.planned', 'production_order', $order->id, null, ['order_no' => $order->order_no, 'quantity' => $quantity]);

            return $this->present($order);
        });
    }

    /**
     * @param  array<string, string>  $usedQuantities  product id => quantity really used; missing = as required
     */
    public function complete(string $orderId, string $produced, string $extraCost, array $usedQuantities, ?string $batchNo, ?string $expiryDate): ProductionOrder
    {
        if (! Decimal::isPositive($produced)) {
            throw new \DomainException('Enter the quantity produced.');
        }
        if (Decimal::isNegative($extraCost)) {
            throw new \DomainException('Extra cost cannot be negative.');
        }

        return DB::transaction(function () use ($orderId, $produced, $extraCost, $usedQuantities, $batchNo, $expiryDate): ProductionOrder {
            // The row lock makes a double-click complete exactly once.
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== ProductionOrder::PLANNED) {
                throw new \DomainException("Production order {$order->order_no} is already {$order->status}.");
            }

            $materialCost = '0';
            foreach ($order->items as $item) {
                $used = (string) ($usedQuantities[$item->product_id] ?? $item->required_quantity);
                if (Decimal::isNegative($used)) {
                    throw new \DomainException('Used quantities cannot be negative.');
                }

                $unitCost = '0';
                if (Decimal::isPositive($used)) {
                    $unitCost = $this->ledger->issue(new StockPosting(
                        $order->company_id, $order->warehouse_id, $item->product_id, $used,
                        MovementType::ProductionIssue, 'production_order', $order->id,
                    ));
                }

                $item->update(['issued_quantity' => $used, 'unit_cost' => $unitCost]);
                $materialCost = Decimal::add($materialCost, Decimal::mul($used, $unitCost, 4));
            }

            // Work steps priced at their work centres' hourly rates, on top of any extra cost entered.
            $operations = $this->operationsCost(Recipe::query()->findOrFail($order->recipe_id), $produced);
            $extraCost = Decimal::add(Decimal::round($extraCost, 2), $operations, 2);
            $totalCost = Decimal::add(Decimal::round($materialCost, 2), Decimal::round($extraCost, 2));
            $unitCost = Decimal::div($totalCost, $produced, Decimal::MONEY_SCALE);

            $this->ledger->receive(new StockPosting(
                $order->company_id, $order->warehouse_id, $order->product_id, $produced,
                MovementType::ProductionReceipt, 'production_order', $order->id,
                batchNo: $batchNo, expiryDate: $expiryDate,
            ), $unitCost);

            $journalId = null;
            if (Decimal::isPositive($extraCost)) {
                $branch = Warehouse::query()->whereKey($order->warehouse_id)->value('branch_id');
                $amount = Decimal::round($extraCost, 2);
                $journalId = $this->postJournal->execute(new JournalDraft(
                    companyId: $order->company_id,
                    postingDate: now()->toDateString(),
                    sourceType: 'production_order',
                    sourceId: $order->id,
                    description: "Production {$order->order_no}: labour and overheads",
                    lines: [
                        JournalLineDraft::debit($this->accounts->id($order->company_id, 'inventory'), $amount, $branch),
                        JournalLineDraft::credit($this->accounts->id($order->company_id, 'production_overhead'), $amount, $branch),
                    ],
                ))->id;
            }

            $order->update([
                'status' => ProductionOrder::COMPLETED,
                'produced_quantity' => $produced,
                'material_cost' => Decimal::round($materialCost, 2),
                'extra_cost' => Decimal::round($extraCost, 2),
                'operations_cost' => $operations,
                'unit_cost' => $unitCost,
                'batch_no' => $batchNo,
                'expiry_date' => $expiryDate,
                'journal_entry_id' => $journalId,
                'completed_by' => $this->auth->guard('sanctum')->id(),
                'completed_at' => now(),
            ]);

            $this->audit->record('production.order.completed', 'production_order', $order->id, null, [
                'order_no' => $order->order_no, 'produced' => $produced, 'unit_cost' => $unitCost,
            ]);

            return $this->present($order);
        });
    }

    public function cancel(string $orderId): ProductionOrder
    {
        return DB::transaction(function () use ($orderId): ProductionOrder {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== ProductionOrder::PLANNED) {
                throw new \DomainException("Production order {$order->order_no} is already {$order->status}.");
            }
            $order->update(['status' => ProductionOrder::CANCELLED]);
            $this->audit->record('production.order.cancelled', 'production_order', $order->id, null, ['order_no' => $order->order_no]);

            return $this->present($order);
        });
    }

    private function present(ProductionOrder $order): ProductionOrder
    {
        return $order->refresh()->load(['product:id,sku,name', 'warehouse:id,name', 'items.product:id,sku,name']);
    }

    /** Cost of the recipe's work steps for a produced quantity: minutes × hourly rate. */
    private function operationsCost(Recipe $recipe, string $produced): string
    {
        $factor = Decimal::div($produced, (string) $recipe->yield_quantity, 10);
        $cost = '0';
        foreach ($recipe->operations()->get() as $op) {
            $rate = (string) (WorkCentre::query()->whereKey($op->work_centre_id)->value('hourly_rate') ?? '0');
            $cost = Decimal::add($cost, Decimal::div(Decimal::mul(Decimal::mul((string) $op->minutes, $factor, 10), $rate, 10), '60', 6), 6);
        }

        return Decimal::round($cost, 2);
    }
}
