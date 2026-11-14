<?php

declare(strict_types=1);

namespace Sales\Application;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;
use Symfony\Component\Uid\Uuid;

/**
 * Customer loyalty points.
 *
 * Two tenant settings drive it (group "loyalty"):
 *   earn_per_amount — money spent per point earned (0 switches earning off)
 *   point_value     — money one point is worth when redeemed
 *
 * Points are whole numbers. Callers must hold a row lock on the customer.
 */
final class LoyaltyProgram
{
    public function __construct(
        private readonly Settings $settings,
        private readonly TenantContext $tenant,
        private readonly AuthFactory $auth,
    ) {}

    /** @return array{earn_per_amount: string, point_value: string, enabled: bool} */
    public function config(): array
    {
        $earn = (string) ($this->settings->get('loyalty', 'earn_per_amount') ?? '0');
        $value = (string) ($this->settings->get('loyalty', 'point_value') ?? '0');

        return [
            'earn_per_amount' => $earn,
            'point_value' => $value,
            'enabled' => Decimal::isPositive($earn) && Decimal::isPositive($value),
        ];
    }

    /** Points earned on an amount actually paid (rounded down). */
    public function pointsFor(string $amountPaid): int
    {
        $rate = $this->config()['earn_per_amount'];

        if (! Decimal::isPositive($rate) || ! Decimal::isPositive($amountPaid)) {
            return 0;
        }

        return (int) bcdiv($amountPaid, $rate, 0);
    }

    /** Points needed to cover an amount (rounded up, so a redemption is never under-charged). */
    public function pointsToCover(string $amount): int
    {
        $value = $this->config()['point_value'];

        if (! Decimal::isPositive($value)) {
            throw new \DomainException('Loyalty redemption is not set up. Set a point value under Pricing & deals → Loyalty.');
        }

        $points = bcdiv($amount, $value, 0);

        return (int) (Decimal::cmp(bcmul($points, $value, 4), $amount) < 0 ? bcadd($points, '1', 0) : $points);
    }

    /** Apply a signed change to a locked customer and write the ledger row. */
    public function move(Customer $customer, int $points, string $type, ?string $sourceType, ?string $sourceId, ?string $note = null): void
    {
        if ($points === 0) {
            return;
        }

        if ($points < 0 && $customer->loyalty_points + $points < 0 && $type !== 'reverse') {
            throw new \DomainException("{$customer->name} has {$customer->loyalty_points} points; ".abs($points).' are needed.');
        }

        $customer->forceFill(['loyalty_points' => $customer->loyalty_points + $points])->save();

        DB::table('loyalty_transactions')->insert([
            'id' => Uuid::v7()->toRfc4122(),
            'tenant_id' => $this->tenant->requireId(),
            'customer_id' => $customer->id,
            'type' => $type,
            'points' => $points,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $note,
            'created_by' => $this->auth->guard('sanctum')->id(),
            'created_at' => now(),
        ]);
    }
}
