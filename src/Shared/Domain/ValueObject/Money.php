<?php

declare(strict_types=1);

namespace Shared\Domain\ValueObject;

use Shared\Domain\Exception\CurrencyMismatch;

final readonly class Money
{
    private const int SCALE = 4;

    public function __construct(
        public string   $amount,
        public Currency $currency,
    ) {}

    public static function zero(Currency $currency): self
    {
        return new self('0', $currency);
    }

    public static function of(string|int $amount, Currency $currency): self
    {
        return new self((string) $amount, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            bcadd($this->amount, $other->amount, self::SCALE),
            $this->currency,
        );
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            bcsub($this->amount, $other->amount, self::SCALE),
            $this->currency,
        );
    }

    public function multiply(string $factor): self
    {
        return new self(
            bcmul($this->amount, $factor, self::SCALE),
            $this->currency,
        );
    }

    public function negate(): self
    {
        return new self(
            bcmul($this->amount, '-1', self::SCALE),
            $this->currency,
        );
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE) > 0;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE) < 0;
    }

    public function rounded(): string
    {
        return bcadd($this->amount, '0', $this->currency->decimalPlaces());
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
