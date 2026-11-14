<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Inventory\Domain\Enum\MovementType;
use Inventory\Domain\StockPosting;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;
use Symfony\Component\Uid\Uuid;

/**
 * Batch / expiry tracking, layered on the stock ledger for products that
 * have "track batch" or "track expiry" switched on. Other products never
 * touch these tables.
 *
 *  - Stock comes in to a named batch (or "UNBATCHED" when none is given).
 *  - Stock goes out first-expiry-first-out. A sale never takes from an
 *    expired batch; write-offs and transfers can.
 *  - Every outgoing allocation is recorded, so stock that comes back goes
 *    to the batch it left.
 *
 * Called by EloquentStockLedger, inside its transaction and balance lock.
 */
final class LotLedger
{
    public const string UNBATCHED = 'UNBATCHED';

    /** @var array<string, object|null> */
    private array $products = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function receive(StockPosting $posting): void
    {
        $product = $this->tracked($posting->productId);

        if ($product === null) {
            return;
        }

        if ($posting->batchNo !== null && trim($posting->batchNo) !== '') {
            $this->addToLot($posting, strtoupper(trim($posting->batchNo)), $posting->expiryDate, $posting->quantity);

            return;
        }

        if ($product->track_expiry && $posting->type === MovementType::PurchaseReceipt && $posting->expiryDate === null) {
            throw new \DomainException("{$product->name} tracks expiry: enter the expiry date of the batch being received.");
        }

        $remaining = $posting->quantity;

        // Coming back from a document that took it out: return it to those batches.
        if ($posting->lotsFromSourceType !== null && $posting->lotsFromSourceId !== null) {
            $allocations = DB::table('stock_lot_allocations as a')
                ->join('stock_lots as l', 'l.id', '=', 'a.lot_id')
                ->where('a.source_type', $posting->lotsFromSourceType)
                ->where('a.source_id', $posting->lotsFromSourceId)
                ->where('a.product_id', $posting->productId)
                ->orderBy('a.id')
                ->get(['l.batch_no', 'l.expiry_date', 'a.quantity']);

            foreach ($allocations as $allocation) {
                if (! Decimal::isPositive($remaining)) {
                    break;
                }
                $take = Decimal::cmp((string) $allocation->quantity, $remaining) < 0 ? (string) $allocation->quantity : $remaining;
                $this->addToLot($posting, $allocation->batch_no, $allocation->expiry_date, $take);
                $remaining = Decimal::sub($remaining, $take, Decimal::QTY_SCALE);
            }
        }

        if (Decimal::isPositive($remaining)) {
            $this->addToLot($posting, self::UNBATCHED, $posting->expiryDate, $remaining);
        }
    }

    /**
     * @param  string  $onHandBefore  the warehouse balance before this issue (some of it may predate batch tracking)
     */
    public function issue(StockPosting $posting, string $onHandBefore): void
    {
        $product = $this->tracked($posting->productId);

        if ($product === null) {
            return;
        }

        $lots = DB::table('stock_lots')
            ->where('warehouse_id', $posting->warehouseId)->where('product_id', $posting->productId)
            ->where('quantity', '>', 0)
            ->orderByRaw('expiry_date ASC NULLS LAST')->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        $inLots = Decimal::sum($lots, 'quantity', Decimal::QTY_SCALE);
        // Stock received before tracking was switched on belongs to no batch.
        $untracked = Decimal::sub($onHandBefore, $inLots, Decimal::QTY_SCALE);
        $remaining = $posting->quantity;
        $today = now()->toDateString();
        $skippedExpired = '0';

        foreach ($lots as $lot) {
            if (! Decimal::isPositive($remaining)) {
                break;
            }

            if ($posting->type === MovementType::Sale && $lot->expiry_date !== null && $lot->expiry_date < $today) {
                $skippedExpired = Decimal::add($skippedExpired, (string) $lot->quantity, Decimal::QTY_SCALE);

                continue;
            }

            $take = Decimal::cmp((string) $lot->quantity, $remaining) < 0 ? (string) $lot->quantity : $remaining;

            DB::table('stock_lots')->where('id', $lot->id)->update([
                'quantity' => Decimal::sub((string) $lot->quantity, $take, Decimal::QTY_SCALE),
                'updated_at' => now(),
            ]);
            DB::table('stock_lot_allocations')->insert([
                'tenant_id' => $this->tenant->requireId(),
                'lot_id' => $lot->id,
                'product_id' => $posting->productId,
                'source_type' => $posting->sourceType,
                'source_id' => $posting->sourceId,
                'quantity' => $take,
                'created_at' => now(),
            ]);

            $remaining = Decimal::sub($remaining, $take, Decimal::QTY_SCALE);
        }

        if (Decimal::isPositive($remaining) && Decimal::cmp($remaining, $untracked) > 0) {
            throw new \DomainException(Decimal::isPositive($skippedExpired)
                ? "{$product->name}: not enough unexpired stock. {$this->trim($skippedExpired)} in this warehouse has expired — write it off with a stock adjustment."
                : "{$product->name}: batch records do not cover this quantity. Run a stock count for this warehouse.");
        }
    }

    private function addToLot(StockPosting $posting, string $batchNo, ?string $expiryDate, string $quantity): void
    {
        $key = ['warehouse_id' => $posting->warehouseId, 'product_id' => $posting->productId, 'batch_no' => $batchNo];

        DB::table('stock_lots')->insertOrIgnore($key + [
            'id' => Uuid::v7()->toRfc4122(),
            'tenant_id' => $this->tenant->requireId(),
            'expiry_date' => $expiryDate,
            'quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lot = DB::table('stock_lots')->where($key)->lockForUpdate()->first();

        DB::table('stock_lots')->where('id', $lot->id)->update([
            'quantity' => Decimal::add((string) $lot->quantity, $quantity, Decimal::QTY_SCALE),
            // A batch has one expiry date; the first one recorded stands.
            'expiry_date' => $lot->expiry_date ?? $expiryDate,
            'updated_at' => now(),
        ]);
    }

    /** The product row when it tracks batch or expiry, otherwise null. */
    private function tracked(string $productId): ?object
    {
        if (! array_key_exists($productId, $this->products)) {
            $product = DB::table('products')->where('id', $productId)->where('tenant_id', $this->tenant->requireId())
                ->first(['id', 'name', 'track_batch', 'track_expiry']);

            $this->products[$productId] = $product !== null && ($product->track_batch || $product->track_expiry) ? $product : null;
        }

        return $this->products[$productId];
    }

    private function trim(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
