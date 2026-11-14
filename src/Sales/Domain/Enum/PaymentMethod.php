<?php

declare(strict_types=1);

namespace Sales\Domain\Enum;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Wallet = 'wallet';
    case Credit = 'credit'; // on customer account
    case Loyalty = 'loyalty'; // paid with loyalty points
    case GiftCard = 'gift_card'; // stored-value card or voucher; reference = card number

    /** Posting-account key the tender is debited to. */
    public function postingAccount(): string
    {
        return match ($this) {
            self::Cash => 'cash',
            self::Card, self::BankTransfer, self::Wallet => 'bank',
            self::Credit => 'accounts_receivable',
            self::Loyalty => 'loyalty_redemption',
            self::GiftCard => 'gift_card_liability',
        };
    }
}
