<?php

declare(strict_types=1);

namespace Finance\Application\Dto;

final readonly class JournalLineDraft
{
    private function __construct(
        public string $accountId,
        public string $debit,
        public string $credit,
        public ?string $branchId,
        public ?string $partyType,
        public ?string $partyId,
        public ?string $description,
    ) {}

    public static function debit(string $accountId, string $amount, ?string $branchId = null, ?string $description = null, ?string $partyType = null, ?string $partyId = null): self
    {
        return new self($accountId, $amount, '0', $branchId, $partyType, $partyId, $description);
    }

    public static function credit(string $accountId, string $amount, ?string $branchId = null, ?string $description = null, ?string $partyType = null, ?string $partyId = null): self
    {
        return new self($accountId, '0', $amount, $branchId, $partyType, $partyId, $description);
    }

    public function amount(): string
    {
        return bccomp($this->debit, '0', 4) > 0 ? $this->debit : $this->credit;
    }
}
