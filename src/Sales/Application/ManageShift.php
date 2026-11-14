<?php

declare(strict_types=1);

namespace Sales\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Sales\Domain\Exception\ShiftNotOpen;
use Sales\Domain\Exception\TerminalOccupied;
use Sales\Infrastructure\Persistence\Eloquent\CashMovement;
use Sales\Infrastructure\Persistence\Eloquent\PosShift;
use Sales\Infrastructure\Persistence\Eloquent\PosTerminal;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

final class ManageShift
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly AuditTrail $audit,
    ) {}

    public function open(PosTerminal $terminal, string $cashierId, string $openingCash): PosShift
    {
        try {
            return DB::transaction(function () use ($terminal, $cashierId, $openingCash): PosShift {
                $branch = Branch::query()->findOrFail($terminal->branch_id);

                return PosShift::query()->create([
                    'company_id' => $branch->company_id,
                    'branch_id' => $branch->id,
                    'terminal_id' => $terminal->id,
                    'cashier_id' => $cashierId,
                    'shift_no' => $this->numbers->next($this->tenant->requireId(), 'shift', $branch->id, 'SH'),
                    'status' => 'open',
                    'opening_cash' => Decimal::round($openingCash, 2),
                    'opened_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The partial unique indexes on pos_shifts are the source of truth here.
            throw TerminalOccupied::create();
        }
    }

    public function recordCashMovement(PosShift $shift, string $type, string $amount, string $reason, string $actorId): CashMovement
    {
        return DB::transaction(function () use ($shift, $type, $amount, $reason, $actorId): CashMovement {
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $locked->isOpen()) {
                throw ShiftNotOpen::alreadyClosed($locked->shift_no);
            }

            $movement = CashMovement::query()->create([
                'shift_id' => $locked->id,
                'type' => $type,
                'amount' => Decimal::round($amount, 2),
                'reason' => $reason,
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            $this->audit->record('sales.shift.cash_'.$type, 'pos_shift', $locked->id, null, ['amount' => $movement->amount], $reason);

            return $movement;
        });
    }

    /**
     * Expected drawer cash for a shift:
     * opening float + cash taken − change given + paid-in − paid-out.
     *
     * @return array{opening_cash: string, cash_sales: string, paid_in: string, paid_out: string, expected_cash: string, sales_count: int, sales_total: string, by_method: array<string, string>}
     */
    public function summary(PosShift $shift): array
    {
        $tenders = DB::table('sale_tenders as t')
            ->join('sales as s', 's.id', '=', 't.sale_id')
            ->where('s.shift_id', $shift->id)->where('s.status', Sale::COMPLETED)
            ->groupBy('t.method')
            ->selectRaw('t.method, SUM(t.amount) AS total')
            ->pluck('total', 'method')
            ->map(static fn ($v): string => (string) $v)
            ->all();

        $sales = DB::table('sales')->where('shift_id', $shift->id)->where('status', Sale::COMPLETED)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS total, COALESCE(SUM(change_total), 0) AS change_given')
            ->first();

        $moves = DB::table('pos_cash_movements')->where('shift_id', $shift->id)
            ->groupBy('type')->selectRaw('type, SUM(amount) AS total')->pluck('total', 'type');

        $cashSales = Decimal::sub($tenders['cash'] ?? '0', (string) $sales->change_given);
        $paidIn = (string) ($moves['paid_in'] ?? '0');
        $paidOut = (string) ($moves['paid_out'] ?? '0');

        return [
            'opening_cash' => (string) $shift->opening_cash,
            'cash_sales' => $cashSales,
            'paid_in' => $paidIn,
            'paid_out' => $paidOut,
            'expected_cash' => Decimal::sub(Decimal::add(Decimal::add((string) $shift->opening_cash, $cashSales), $paidIn), $paidOut),
            'sales_count' => (int) $sales->n,
            'sales_total' => (string) $sales->total,
            'by_method' => $tenders,
        ];
    }

    public function close(PosShift $shift, string $countedCash, ?string $notes): PosShift
    {
        return DB::transaction(function () use ($shift, $countedCash, $notes): PosShift {
            // Same lock RecordSale takes, so no sale can slip in while we total up.
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $locked->isOpen()) {
                throw ShiftNotOpen::alreadyClosed($locked->shift_no);
            }

            $expected = $this->summary($locked)['expected_cash'];
            $counted = Decimal::round($countedCash, 2);
            $variance = Decimal::sub($counted, $expected);

            $locked->update([
                'status' => 'closed',
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'variance' => $variance,
                'notes' => $notes,
                'closed_at' => now(),
            ]);

            $this->postVariance($locked, $variance);

            $this->audit->record('sales.shift.closed', 'pos_shift', $locked->id, null, [
                'shift_no' => $locked->shift_no, 'expected_cash' => $expected, 'counted_cash' => $counted, 'variance' => $variance,
            ], $notes);

            return $locked;
        });
    }

    /** Shortage: Dr Cash over/short, Cr Cash. Overage: the reverse. */
    private function postVariance(PosShift $shift, string $variance): void
    {
        if (Decimal::isZero($variance)) {
            return;
        }

        $cash = $this->accounts->id($shift->company_id, 'cash');
        $overShort = $this->accounts->id($shift->company_id, 'cash_over_short');
        $amount = ltrim($variance, '-');
        $short = Decimal::isNegative($variance);

        $this->postJournal->execute(new JournalDraft(
            companyId: $shift->company_id,
            postingDate: now()->toDateString(),
            sourceType: 'pos_shift',
            sourceId: $shift->id,
            description: 'Cash '.($short ? 'shortage' : 'overage')." on shift {$shift->shift_no}",
            lines: [
                JournalLineDraft::debit($short ? $overShort : $cash, $amount, $shift->branch_id),
                JournalLineDraft::credit($short ? $cash : $overShort, $amount, $shift->branch_id),
            ],
        ));
    }
}
