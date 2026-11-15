<?php

declare(strict_types=1);

namespace Inventory\Application;

use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;
use Symfony\Component\Uid\Uuid;

/**
 * Holds stock for open sales orders (stock_balances.reserved).
 *
 * Reserved stock cannot be sold at the till or used elsewhere: the stock
 * ledger only issues what is on hand minus reserved. A reservation takes
 * what is free; if less is free than asked, the rest is a shortage and the
 * caller is told. Releasing gives back exactly what was taken.
 * Callers run inside a transaction.
 */
final class StockReservations
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return string the quantity actually reserved */
    public function reserve(string $warehouseId, string $productId, string $quantity): string
    {
        if (! Decimal::isPositive($quantity) || ! $this->stocked($productId)) {
            return '0';
        }
        $balance = $this->lock($warehouseId, $productId);
        $free = Decimal::sub((string) $balance->on_hand, (string) $balance->reserved, Decimal::QTY_SCALE);
        $take = Decimal::cmp($quantity, $free) > 0 ? $free : $quantity;
        if (! Decimal::isPositive($take)) {
            return '0';
        }
        DB::table('stock_balances')->where('id', $balance->id)
            ->update(['reserved' => Decimal::add((string) $balance->reserved, $take, Decimal::QTY_SCALE), 'updated_at' => now()]);

        return $take;
    }

    public function release(string $warehouseId, string $productId, string $quantity): void
    {
        if (! Decimal::isPositive($quantity)) {
            return;
        }
        $balance = $this->lock($warehouseId, $productId);
        $left = Decimal::sub((string) $balance->reserved, $quantity, Decimal::QTY_SCALE);
        DB::table('stock_balances')->where('id', $balance->id)
            ->update(['reserved' => Decimal::isNegative($left) ? '0' : $left, 'updated_at' => now()]);
    }

    /**
     * Free stock for products in a warehouse: on hand, reserved, available.
     *
     * @param  list<string>  $productIds
     * @return array<string, array{on_hand: string, reserved: string, available: string}>
     */
    public function availability(string $warehouseId, array $productIds): array
    {
        $rows = DB::table('stock_balances')->where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)
            ->get(['product_id', 'on_hand', 'reserved'])->keyBy('product_id');
        $out = [];
        foreach ($productIds as $id) {
            $onHand = (string) ($rows[$id]->on_hand ?? '0');
            $reserved = (string) ($rows[$id]->reserved ?? '0');
            $out[$id] = ['on_hand' => $onHand, 'reserved' => $reserved, 'available' => Decimal::sub($onHand, $reserved, Decimal::QTY_SCALE)];
        }

        return $out;
    }

    private function stocked(string $productId): bool
    {
        return DB::table('products')->where('id', $productId)->value('product_type') === 'goods';
    }

    private function lock(string $warehouseId, string $productId): object
    {
        $tenantId = $this->tenant->requireId();
        $owned = DB::table('warehouses')->where('id', $warehouseId)->where('tenant_id', $tenantId)->exists();
        if (! $owned) {
            throw new \DomainException('Unknown warehouse.');
        }
        $key = ['warehouse_id' => $warehouseId, 'product_id' => $productId];
        DB::table('stock_balances')->insertOrIgnore($key + ['id' => Uuid::v7()->toRfc4122(), 'tenant_id' => $tenantId, 'updated_at' => now()]);

        return DB::table('stock_balances')->where($key)->lockForUpdate()->first();
    }
}
