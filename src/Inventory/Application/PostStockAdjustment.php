<?php

declare(strict_types=1);

namespace Inventory\Application;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Inventory\Domain\Enum\MovementType;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Domain\StockPosting;
use Inventory\Infrastructure\Persistence\Eloquent\StockAdjustment;
use Inventory\Infrastructure\Persistence\Eloquent\StockAdjustmentLine;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\EventOutbox;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Stock adjustment (also used for opening balances): one transaction moves
 * the stock, values it and posts the matching journal.
 *
 *   increase:  Dr Inventory        Cr Inventory adjustment (or Opening balance equity)
 *   decrease:  Dr Inventory adjustment        Cr Inventory
 */
final class PostStockAdjustment
{
    public const array REASONS = ['opening_balance', 'count_variance', 'damage', 'expiry', 'other'];

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly EventOutbox $outbox,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  list<array{product_id: string, qty_delta: string, unit_cost?: string|null, batch_no?: string|null, expiry_date?: string|null}>  $lines
     */
    public function execute(Warehouse $warehouse, string $reasonCode, ?string $notes, array $lines): StockAdjustment
    {
        return DB::transaction(function () use ($warehouse, $reasonCode, $notes, $lines): StockAdjustment {
            $adjustment = StockAdjustment::query()->create([
                'company_id' => $warehouse->company_id,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'adjustment_no' => $this->numbers->next($this->tenant->requireId(), 'stock_adjustment', $warehouse->company_id, 'ADJ'),
                'reason_code' => $reasonCode,
                'notes' => $notes,
                'created_by' => $this->auth->guard('sanctum')->id(),
                'posted_at' => now(),
            ]);

            $increase = '0';
            $decrease = '0';

            foreach ($lines as $line) {
                $product = Product::query()->findOrFail($line['product_id']);

                if (! $product->product_type->tracksInventory()) {
                    throw new \DomainException("{$product->name} is not a stock item.");
                }

                $qty = (string) $line['qty_delta'];
                $posting = new StockPosting(
                    companyId: $warehouse->company_id,
                    warehouseId: $warehouse->id,
                    productId: $product->id,
                    quantity: ltrim($qty, '-'),
                    type: Decimal::isPositive($qty) ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                    sourceType: 'stock_adjustment',
                    sourceId: $adjustment->id,
                    note: $reasonCode,
                    batchNo: $line['batch_no'] ?? null,
                    expiryDate: $line['expiry_date'] ?? null,
                );

                if (Decimal::isPositive($qty)) {
                    $unitCost = $this->ledger->receive($posting, (string) ($line['unit_cost'] ?? $product->cost_price));
                    $value = Decimal::mul($qty, $unitCost, 2);
                    $increase = Decimal::add($increase, $value);
                } else {
                    $unitCost = $this->ledger->issue($posting);
                    $value = Decimal::mul($qty, $unitCost, 2); // negative
                    $decrease = Decimal::sub($decrease, $value);
                }

                StockAdjustmentLine::query()->create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $product->id,
                    'qty_delta' => $qty,
                    'unit_cost' => $unitCost,
                    'value' => $value,
                ]);
            }

            $journalId = $this->postJournal($adjustment, $reasonCode, $increase, $decrease);

            $adjustment->update([
                'total_value' => Decimal::sub($increase, $decrease),
                'journal_entry_id' => $journalId,
            ]);

            $this->audit->record('inventory.adjustment.posted', 'stock_adjustment', $adjustment->id, null, [
                'adjustment_no' => $adjustment->adjustment_no,
                'increase_value' => $increase,
                'decrease_value' => $decrease,
            ], $reasonCode);

            $this->outbox->record('inventory.stock.adjusted', 'stock_adjustment', $adjustment->id, [
                'adjustment_no' => $adjustment->adjustment_no,
                'warehouse_id' => $warehouse->id,
            ]);

            return $adjustment->load('lines.product:id,sku,name');
        });
    }

    private function postJournal(StockAdjustment $adjustment, string $reasonCode, string $increase, string $decrease): ?string
    {
        // Zero-cost adjustments move quantity only; there is nothing to post.
        if (Decimal::isZero($increase) && Decimal::isZero($decrease)) {
            return null;
        }

        $company = $adjustment->company_id;
        $branch = $adjustment->branch_id;
        $inventory = $this->accounts->id($company, 'inventory');
        $offsetForIncrease = $this->accounts->id($company, $reasonCode === 'opening_balance' ? 'opening_balance_equity' : 'inventory_adjustment');

        $entry = $this->postJournal->execute(new JournalDraft(
            companyId: $company,
            postingDate: now()->toDateString(),
            sourceType: 'stock_adjustment',
            sourceId: $adjustment->id,
            description: "Stock adjustment {$adjustment->adjustment_no} ({$reasonCode})",
            lines: [
                JournalLineDraft::debit($inventory, $increase, $branch),
                JournalLineDraft::credit($offsetForIncrease, $increase, $branch),
                JournalLineDraft::debit($this->accounts->id($company, 'inventory_adjustment'), $decrease, $branch),
                JournalLineDraft::credit($inventory, $decrease, $branch),
            ],
        ));

        return $entry->id;
    }
}
