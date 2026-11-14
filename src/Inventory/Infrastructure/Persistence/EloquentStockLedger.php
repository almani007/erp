<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Persistence;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Inventory\Domain\Exception\InsufficientStock;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Domain\StockPosting;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;
use Symfony\Component\Uid\Uuid;

final class EloquentStockLedger implements StockLedger
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthFactory $auth,
    ) {}

    public function receive(StockPosting $posting, string $unitCost): string
    {
        $this->assertValid($posting);

        if (Decimal::isNegative($unitCost)) {
            throw new \DomainException('Unit cost cannot be negative.');
        }

        $balance = $this->lockBalance($posting);
        $onHand = (string) $balance->on_hand;
        $newOnHand = Decimal::add($onHand, $posting->quantity, Decimal::QTY_SCALE);

        // Weighted average. Stock at or below zero has no meaningful cost to
        // blend with, so the incoming cost becomes the average.
        $avgCost = Decimal::isPositive($onHand) && Decimal::isPositive($newOnHand)
            ? Decimal::div(
                Decimal::add(Decimal::mul($onHand, (string) $balance->avg_cost, 8), Decimal::mul($posting->quantity, $unitCost, 8), 8),
                $newOnHand,
            )
            : Decimal::round($unitCost, Decimal::MONEY_SCALE);

        $this->write($posting, $balance->id, $posting->quantity, $unitCost, $newOnHand, $avgCost);

        return $unitCost;
    }

    public function issue(StockPosting $posting): string
    {
        $this->assertValid($posting);

        $balance = $this->lockBalance($posting);
        $onHand = (string) $balance->on_hand;
        $available = Decimal::sub($onHand, (string) $balance->reserved, Decimal::QTY_SCALE);

        if (! config('erp.allow_negative_stock') && Decimal::cmp($available, $posting->quantity) < 0) {
            $name = (string) DB::table('products')->where('id', $posting->productId)->value('name');

            throw InsufficientStock::for($name, $this->trim($available), $this->trim($posting->quantity));
        }

        $unitCost = (string) $balance->avg_cost;
        $newOnHand = Decimal::sub($onHand, $posting->quantity, Decimal::QTY_SCALE);

        $this->write($posting, $balance->id, Decimal::negate($posting->quantity, Decimal::QTY_SCALE), $unitCost, $newOnHand, $unitCost);

        return $unitCost;
    }

    private function assertValid(StockPosting $posting): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock postings must run inside a database transaction.');
        }

        if (! Decimal::isPositive($posting->quantity)) {
            throw new \DomainException('Stock quantity must be greater than zero.');
        }
    }

    /** Lock (creating if needed) the balance row so concurrent postings queue up. */
    private function lockBalance(StockPosting $posting): object
    {
        $tenantId = $this->tenant->requireId();
        $key = ['warehouse_id' => $posting->warehouseId, 'product_id' => $posting->productId];

        // Both ids must belong to this tenant; the raw tables are not tenant-scoped.
        $owned = DB::table('warehouses')->where('id', $posting->warehouseId)->where('tenant_id', $tenantId)->exists()
            && DB::table('products')->where('id', $posting->productId)->where('tenant_id', $tenantId)->exists();

        if (! $owned) {
            throw new \DomainException('Unknown warehouse or product.');
        }

        DB::table('stock_balances')->insertOrIgnore($key + [
            'id' => Uuid::v7()->toRfc4122(),
            'tenant_id' => $tenantId,
            'updated_at' => now(),
        ]);

        return DB::table('stock_balances')->where($key)->lockForUpdate()->first();
    }

    private function write(StockPosting $posting, string $balanceId, string $qtyDelta, string $unitCost, string $newOnHand, string $avgCost): void
    {
        DB::table('stock_balances')->where('id', $balanceId)->update([
            'on_hand' => $newOnHand,
            'avg_cost' => $avgCost,
            'updated_at' => now(),
        ]);

        DB::table('stock_movements')->insert([
            'tenant_id' => $this->tenant->requireId(),
            'company_id' => $posting->companyId,
            'warehouse_id' => $posting->warehouseId,
            'product_id' => $posting->productId,
            'movement_type' => $posting->type->value,
            'qty_delta' => $qtyDelta,
            'unit_cost' => $unitCost,
            'balance_after' => $newOnHand,
            'source_type' => $posting->sourceType,
            'source_id' => $posting->sourceId,
            'note' => $posting->note,
            'created_by' => $this->auth->guard('sanctum')->id(),
            'occurred_at' => now(),
        ]);
    }

    private function trim(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
