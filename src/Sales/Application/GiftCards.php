<?php

declare(strict_types=1);

namespace Sales\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Sales\Infrastructure\Persistence\Eloquent\GiftCard;
use Sales\Infrastructure\Persistence\Eloquent\PosShift;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Support\Decimal;
use Symfony\Component\Uid\Uuid;

/**
 * Gift cards and vouchers: stored value that customers spend at the till.
 *
 *   Sold for cash / bank     Dr Cash / Bank              Cr Gift cards outstanding
 *   Given as a promotion     Dr Promotional vouchers     Cr Gift cards outstanding
 *   Store credit on return   (the return itself credits Gift cards outstanding)
 *   Spent on a sale          Dr Gift cards outstanding   (sale journal, tender "gift_card")
 *   Expired with value left  Dr Gift cards outstanding   Cr Gift card breakage
 *
 * Callers run inside a transaction; every balance change takes a row lock.
 */
final class GiftCards
{
    public const array PAID_BY = ['cash', 'bank', 'promotion'];

    public function __construct(
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
        private readonly ManageShift $shifts,
    ) {}

    /**
     * @param  array{company_id: string, branch_id?: string|null, kind?: string, value: string, paid_by: string, customer_id?: string|null, expires_on?: string|null, code?: string|null, note?: string|null}  $data
     */
    public function issue(array $data): GiftCard
    {
        return DB::transaction(function () use ($data): GiftCard {
            $value = Decimal::round($data['value'], 2);
            if (! Decimal::isPositive($value)) {
                throw new \DomainException('The card value must be greater than zero.');
            }

            $card = $this->create($data['company_id'], $data['branch_id'] ?? null, $data['kind'] ?? 'gift_card', $value,
                $data['paid_by'], $data['customer_id'] ?? null, $data['expires_on'] ?? null, $data['code'] ?? null, $data['note'] ?? null);

            $debitKey = match ($data['paid_by']) { 'cash' => 'cash', 'bank' => 'bank', default => 'promotional_vouchers' };
            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $card->company_id,
                postingDate: now()->toDateString(),
                sourceType: 'gift_card',
                sourceId: $card->id,
                description: ($card->kind === 'voucher' ? 'Voucher ' : 'Gift card ').$card->code.' issued',
                lines: [
                    JournalLineDraft::debit($this->accounts->id($card->company_id, $debitKey), $value, $card->branch_id),
                    JournalLineDraft::credit($this->accounts->id($card->company_id, 'gift_card_liability'), $value, $card->branch_id),
                ],
            ));
            $card->update(['journal_entry_id' => $journal->id]);

            // Cash for a card sold at the till goes into that cashier's drawer count.
            $actor = $this->auth->guard('sanctum')->id();
            if ($data['paid_by'] === 'cash' && $actor !== null) {
                $shift = PosShift::query()->where('cashier_id', $actor)->where('status', 'open')->first();
                if ($shift !== null) {
                    $this->shifts->recordCashMovement($shift, 'paid_in', $value, "Gift card {$card->code} sold", (string) $actor);
                }
            }

            $this->audit->record('sales.gift_card.issued', 'gift_card', $card->id, null, ['code' => $card->code, 'value' => $value, 'paid_by' => $data['paid_by']]);

            return $card->load('customer:id,name,customer_code,phone');
        });
    }

    /** Store credit for a return: the return journal has already credited the liability. */
    public function issueStoreCredit(string $companyId, ?string $branchId, string $value, ?string $customerId, string $returnId, string $returnNo): GiftCard
    {
        $card = $this->create($companyId, $branchId, 'voucher', Decimal::round($value, 2), 'return', $customerId, null, null, "Store credit for return {$returnNo}", 'sales_return', $returnId);
        $this->audit->record('sales.gift_card.issued', 'gift_card', $card->id, null, ['code' => $card->code, 'value' => $value, 'paid_by' => 'return']);

        return $card;
    }

    /** Spends value from a card for a sale. Throws when the card cannot cover it. */
    public function redeem(string $code, string $amount, string $companyId, string $saleId): GiftCard
    {
        $card = GiftCard::query()->where('code', self::normalise($code))->lockForUpdate()->first()
            ?? throw new \DomainException("Gift card {$code} was not found.");
        $this->assertUsable($card, $companyId);

        $amount = Decimal::round($amount, 2);
        if (Decimal::cmp($amount, (string) $card->balance) > 0) {
            throw new \DomainException("Gift card {$card->code} has only ".Decimal::round((string) $card->balance, 2).' left.');
        }

        $this->move($card, Decimal::negate($amount, 2), 'redeem', 'sale', $saleId);

        return $card;
    }

    /** Puts back what a voided sale took from cards. */
    public function reverseFor(string $sourceType, string $sourceId): void
    {
        $spent = DB::table('gift_card_transactions')->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('kind', 'redeem')->get(['gift_card_id', 'amount']);

        foreach ($spent as $row) {
            $card = GiftCard::query()->lockForUpdate()->findOrFail($row->gift_card_id);
            $this->move($card, Decimal::negate((string) $row->amount, 2), 'reverse', $sourceType, $sourceId);
        }
    }

    public function setBlocked(string $cardId, bool $blocked, string $reason): GiftCard
    {
        return DB::transaction(function () use ($cardId, $blocked, $reason): GiftCard {
            $card = GiftCard::query()->lockForUpdate()->findOrFail($cardId);
            if (in_array($card->status, ['used', 'expired'], true)) {
                throw new \DomainException("Gift card {$card->code} is {$card->status}.");
            }
            $card->update(['status' => $blocked ? 'blocked' : 'active']);
            $this->audit->record($blocked ? 'sales.gift_card.blocked' : 'sales.gift_card.unblocked', 'gift_card', $card->id, null, ['code' => $card->code], $reason);

            return $card;
        });
    }

    /**
     * Expires cards past their date. The value left becomes income (breakage).
     *
     * @return int cards expired
     */
    public function expireDue(?string $tenantId = null, ?string $today = null): int
    {
        $today ??= now()->toDateString();
        $count = 0;

        $due = GiftCard::query()->withoutGlobalScope('tenant')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'active')->whereNotNull('expires_on')->where('expires_on', '<', $today)
            ->orderBy('tenant_id')->get(['id', 'tenant_id']);

        foreach ($due as $row) {
            app(\Shared\Domain\Contract\TenantContext::class)->set($row->tenant_id);
            DB::transaction(function () use ($row, $today, &$count): void {
                $card = GiftCard::query()->lockForUpdate()->find($row->id);
                if ($card === null || $card->status !== 'active') {
                    return;
                }
                $left = Decimal::round((string) $card->balance, 2);
                if (Decimal::isPositive($left)) {
                    $journal = $this->postJournal->execute(new JournalDraft(
                        companyId: $card->company_id, postingDate: $today, sourceType: 'gift_card_expiry', sourceId: $card->id,
                        description: "Gift card {$card->code} expired",
                        lines: [
                            JournalLineDraft::debit($this->accounts->id($card->company_id, 'gift_card_liability'), $left, $card->branch_id),
                            JournalLineDraft::credit($this->accounts->id($card->company_id, 'gift_card_breakage'), $left, $card->branch_id),
                        ],
                    ));
                    $this->move($card, Decimal::negate($left, 2), 'expire', 'journal_entry', $journal->id);
                }
                $card->update(['status' => 'expired']);
                $count++;
            });
        }

        return $count;
    }

    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $code) ?? '');
    }

    private function create(string $companyId, ?string $branchId, string $kind, string $value, string $paidBy, ?string $customerId,
        ?string $expiresOn, ?string $code, ?string $note, ?string $sourceType = null, ?string $sourceId = null): GiftCard
    {
        $code = $code !== null && $code !== '' ? self::normalise($code) : $this->newCode($kind);
        if (GiftCard::query()->where('code', $code)->exists()) {
            throw new \DomainException("Card number {$code} is already in use.");
        }

        $card = GiftCard::query()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'code' => $code,
            'kind' => $kind,
            'initial_value' => $value,
            'balance' => '0',
            'customer_id' => $customerId,
            'expires_on' => $expiresOn,
            'status' => 'active',
            'paid_by' => $paidBy,
            'note' => $note,
            'issued_by' => $this->auth->guard('sanctum')->id(),
        ]);
        $this->move($card, $value, 'issue', $sourceType ?? 'gift_card', $sourceId ?? $card->id);

        return $card;
    }

    private function assertUsable(GiftCard $card, string $companyId): void
    {
        if ($card->company_id !== $companyId) {
            throw new \DomainException("Gift card {$card->code} belongs to another company.");
        }
        if ($card->status === 'blocked') {
            throw new \DomainException("Gift card {$card->code} is blocked.");
        }
        if ($card->status === 'expired' || ($card->expires_on !== null && $card->expires_on->toDateString() < now()->toDateString())) {
            throw new \DomainException("Gift card {$card->code} has expired.");
        }
        if ($card->status === 'used' || ! Decimal::isPositive((string) $card->balance)) {
            throw new \DomainException("Gift card {$card->code} has no value left.");
        }
    }

    private function move(GiftCard $card, string $amount, string $kind, ?string $sourceType, ?string $sourceId): void
    {
        $balance = Decimal::add((string) $card->balance, $amount, 2);
        if (Decimal::isNegative($balance)) {
            throw new \DomainException("Gift card {$card->code} does not have enough value.");
        }

        $status = $card->status;
        if ($kind === 'redeem' && ! Decimal::isPositive($balance)) {
            $status = 'used';
        } elseif ($kind === 'reverse' && $status === 'used') {
            $status = 'active';
        }
        $card->forceFill(['balance' => $balance, 'status' => $status])->save();

        DB::table('gift_card_transactions')->insert([
            'id' => (string) Uuid::v7(),
            'tenant_id' => $card->tenant_id,
            'gift_card_id' => $card->id,
            'kind' => $kind,
            'amount' => $amount,
            'balance_after' => $balance,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $this->auth->guard('sanctum')->id(),
            'created_at' => now(),
        ]);
    }

    /** 16 characters, no look-alike letters, grouped for reading aloud: GC-7K3M-9QXR-4TWD. */
    private function newCode(string $kind): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = ($kind === 'voucher' ? 'VC-' : 'GC-').implode('-', str_split($raw, 4));
        } while (GiftCard::query()->where('code', $code)->exists());

        return $code;
    }
}
