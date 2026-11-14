<?php

declare(strict_types=1);

namespace Inventory\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inventory\Infrastructure\Persistence\Eloquent\StockCount;
use Inventory\Infrastructure\Persistence\Eloquent\StockCountLine;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Physical stock count (INV-005).
 *
 * start → a sheet listing every product with a balance in the warehouse
 * enter → counted quantities are saved as the team works through the sheet
 * post  → each counted line is compared with the stock on hand *at that
 *         moment* (sales may have happened since the sheet was printed) and
 *         the differences go out as one stock adjustment with its journal.
 *         Lines left blank are not adjusted.
 */
final class ConductStockCount
{
    public function __construct(
        private readonly PostStockAdjustment $adjust,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function start(Warehouse $warehouse, ?string $notes, string $actorId): StockCount
    {
        try {
            return DB::transaction(function () use ($warehouse, $notes, $actorId): StockCount {
                $count = StockCount::query()->create([
                    'company_id' => $warehouse->company_id,
                    'branch_id' => $warehouse->branch_id,
                    'warehouse_id' => $warehouse->id,
                    'count_no' => $this->numbers->next($this->tenant->requireId(), 'stock_count', $warehouse->company_id, 'CNT'),
                    'status' => StockCount::IN_PROGRESS,
                    'notes' => $notes,
                    'started_by' => $actorId,
                    'started_at' => now(),
                ]);

                $balances = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)
                    ->where('tenant_id', $this->tenant->requireId())->get(['product_id', 'on_hand']);

                foreach ($balances as $balance) {
                    StockCountLine::query()->create([
                        'stock_count_id' => $count->id,
                        'product_id' => $balance->product_id,
                        'system_qty' => $balance->on_hand,
                    ]);
                }

                return $count;
            });
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException("A stock count is already in progress for {$warehouse->name}. Post or cancel it first.");
        }
    }

    /** @param  list<array{id: string, counted_qty: string|int|float|null}>  $lines */
    public function enter(StockCount $count, array $lines): void
    {
        DB::transaction(function () use ($count, $lines): void {
            $locked = $this->lockOpen($count->id);

            foreach ($lines as $line) {
                StockCountLine::query()->where('stock_count_id', $locked->id)->whereKey($line['id'])
                    ->update(['counted_qty' => $line['counted_qty']]);
            }
        });
    }

    public function post(StockCount $count, string $actorId): StockCount
    {
        return DB::transaction(function () use ($count, $actorId): StockCount {
            $locked = $this->lockOpen($count->id);
            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);

            $onHand = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()->pluck('on_hand', 'product_id');

            $adjustments = [];
            foreach ($locked->lines()->whereNotNull('counted_qty')->get() as $line) {
                $variance = Decimal::sub((string) $line->counted_qty, (string) ($onHand[$line->product_id] ?? '0'), Decimal::QTY_SCALE);
                $line->update(['variance_qty' => $variance]);

                if (! Decimal::isZero($variance)) {
                    $adjustments[] = ['product_id' => $line->product_id, 'qty_delta' => $variance];
                }
            }

            $adjustmentId = $adjustments === []
                ? null
                : $this->adjust->execute($warehouse, 'count_variance', "Stock count {$locked->count_no}", $adjustments)->id;

            $locked->update([
                'status' => StockCount::POSTED,
                'stock_adjustment_id' => $adjustmentId,
                'posted_by' => $actorId,
                'posted_at' => now(),
            ]);

            $this->audit->record('inventory.count.posted', 'stock_count', $locked->id, null, [
                'count_no' => $locked->count_no, 'lines_adjusted' => count($adjustments),
            ]);

            return $locked;
        });
    }

    public function cancel(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $locked = $this->lockOpen($count->id);
            $locked->update(['status' => StockCount::CANCELLED]);
            $this->audit->record('inventory.count.cancelled', 'stock_count', $locked->id, null, ['count_no' => $locked->count_no]);

            return $locked;
        });
    }

    private function lockOpen(string $countId): StockCount
    {
        $count = StockCount::query()->lockForUpdate()->findOrFail($countId);

        if ($count->status !== StockCount::IN_PROGRESS) {
            throw new \DomainException("Stock count {$count->count_no} is {$count->status} and can no longer be changed.");
        }

        return $count;
    }
}
