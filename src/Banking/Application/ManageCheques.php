<?php

declare(strict_types=1);

namespace Banking\Application;

use Banking\Infrastructure\Persistence\Eloquent\BankAccount;
use Banking\Infrastructure\Persistence\Eloquent\Cheque;
use Illuminate\Support\Facades\DB;
use Purchasing\Application\PaySupplier;
use Sales\Application\ReceiveCustomerPayment;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Support\Decimal;

/**
 * Cheque register. A cheque is only a promise until the bank clears it,
 * so nothing is posted while it is in hand or deposited. Clearing records
 * the customer receipt (or supplier payment) into that bank account, which
 * pays off the oldest invoices / bills. A bounced cheque leaves the
 * balance owed untouched.
 */
final class ManageCheques
{
    public const array OPEN = ['in_hand', 'deposited', 'issued'];

    public function __construct(
        private readonly ReceiveCustomerPayment $receive,
        private readonly PaySupplier $pay,
        private readonly AuditTrail $audit,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function record(string $companyId, array $data, string $actorId): Cheque
    {
        $issued = $data['direction'] === 'issued';
        if ($issued && empty($data['bank_account_id'])) {
            throw new \DomainException('Choose the bank account the cheque is drawn on.');
        }

        $cheque = Cheque::query()->create([
            'company_id' => $companyId,
            'direction' => $data['direction'],
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'party_type' => $issued ? 'supplier' : 'customer',
            'party_id' => $data['party_id'],
            'cheque_no' => $data['cheque_no'],
            'drawer_bank' => $data['drawer_bank'] ?? null,
            'cheque_date' => $data['cheque_date'],
            'amount' => Decimal::round((string) $data['amount'], 2),
            'status' => $issued ? 'issued' : 'in_hand',
            'notes' => $data['notes'] ?? null,
            'created_by' => $actorId,
        ]);
        $this->audit->record("banking.cheque.{$cheque->direction}", 'cheque', $cheque->id, null, ['cheque_no' => $cheque->cheque_no, 'amount' => $cheque->amount]);

        return $cheque;
    }

    public function deposit(string $id, string $bankAccountId, string $date): Cheque
    {
        return DB::transaction(function () use ($id, $bankAccountId, $date): Cheque {
            $cheque = $this->locked($id, ['in_hand']);
            if ($cheque->cheque_date->toDateString() > $date) {
                throw new \DomainException("Cheque {$cheque->cheque_no} is dated {$cheque->cheque_date->toDateString()} and cannot be deposited before then.");
            }
            $bank = BankAccount::query()->where('kind', 'bank')->where('status', 'active')->findOrFail($bankAccountId);
            $cheque->update(['status' => 'deposited', 'bank_account_id' => $bank->id, 'deposited_on' => $date]);

            return $cheque;
        });
    }

    public function clear(string $id, string $actorId): Cheque
    {
        return DB::transaction(function () use ($id, $actorId): Cheque {
            $cheque = $this->locked($id, ['deposited', 'issued']);
            $bank = BankAccount::query()->findOrFail($cheque->bank_account_id);
            $reference = "Cheque {$cheque->cheque_no}".($cheque->drawer_bank ? " ({$cheque->drawer_bank})" : '');

            $payment = $cheque->direction === 'received'
                ? $this->receive->execute($cheque->party_id, $cheque->company_id, null, 'bank', (string) $cheque->amount, $reference, $actorId, null, $bank->account_id)
                : $this->pay->execute($cheque->party_id, $cheque->company_id, 'bank', (string) $cheque->amount, $reference, $actorId, null, $bank->account_id);

            $cheque->update(['status' => 'cleared', 'cleared_on' => now()->toDateString(), 'payment_id' => $payment->id]);
            $this->audit->record('banking.cheque.cleared', 'cheque', $cheque->id, null, ['cheque_no' => $cheque->cheque_no, 'payment' => $payment->id]);

            return $cheque;
        });
    }

    public function bounce(string $id, string $reason): Cheque
    {
        return DB::transaction(function () use ($id, $reason): Cheque {
            $cheque = $this->locked($id, ['in_hand', 'deposited', 'issued']);
            $cheque->update(['status' => 'bounced', 'bounce_reason' => $reason]);
            $this->audit->record('banking.cheque.bounced', 'cheque', $cheque->id, null, ['cheque_no' => $cheque->cheque_no], $reason);

            return $cheque;
        });
    }

    public function cancel(string $id, string $reason): Cheque
    {
        return DB::transaction(function () use ($id, $reason): Cheque {
            $cheque = $this->locked($id, ['in_hand', 'issued']);
            $cheque->update(['status' => 'cancelled', 'notes' => trim(($cheque->notes ?? '').' Cancelled: '.$reason)]);
            $this->audit->record('banking.cheque.cancelled', 'cheque', $cheque->id, null, ['cheque_no' => $cheque->cheque_no], $reason);

            return $cheque;
        });
    }

    /** @param  list<string>  $allowed */
    private function locked(string $id, array $allowed): Cheque
    {
        $cheque = Cheque::query()->lockForUpdate()->findOrFail($id);
        if (! in_array($cheque->status, $allowed, true)) {
            throw new \DomainException("Cheque {$cheque->cheque_no} is {$cheque->status}.");
        }

        return $cheque;
    }
}
