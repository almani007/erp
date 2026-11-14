<?php

declare(strict_types=1);

namespace Shared\Domain\Support;

/**
 * Exact decimal arithmetic on strings (bcmath). Money and quantities never
 * pass through binary floating point.
 */
final class Decimal
{
    public const int MONEY_SCALE = 4;

    public const int QTY_SCALE = 6;

    private const int WORK_SCALE = 10;

    public static function add(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        return bcsub($a, $b, $scale);
    }

    /** Multiply and round half-up to the requested scale. */
    public static function mul(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        return self::round(bcmul($a, $b, self::WORK_SCALE), $scale);
    }

    /** Divide and round half-up to the requested scale. */
    public static function div(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        if (bccomp($b, '0', self::WORK_SCALE) === 0) {
            throw new \DivisionByZeroError('Division by zero.');
        }

        return self::round(bcdiv($a, $b, self::WORK_SCALE), $scale);
    }

    public static function round(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', self::WORK_SCALE) >= 0
            ? bcadd($value, $half, $scale)
            : bcsub($value, $half, $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::WORK_SCALE);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function isPositive(string $a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function isNegative(string $a): bool
    {
        return self::cmp($a, '0') < 0;
    }

    public static function negate(string $a, int $scale = self::MONEY_SCALE): string
    {
        return bcsub('0', $a, $scale);
    }

    /**
     * Exact sum of one field across rows (objects or arrays).
     *
     * @param  iterable<object|array<string, mixed>>  $rows
     */
    public static function sum(iterable $rows, string $field, int $scale = self::MONEY_SCALE): string
    {
        $total = '0';

        foreach ($rows as $row) {
            $value = is_array($row) ? ($row[$field] ?? '0') : ($row->{$field} ?? '0');
            $total = bcadd($total, (string) $value, $scale);
        }

        return $total;
    }
}
