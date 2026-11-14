<?php

declare(strict_types=1);

namespace Finance\Domain\Enum;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';
    case ContraAsset = 'contra_asset';
    case ContraLiability = 'contra_liability';
    case ContraRevenue = 'contra_revenue';
    case ContraExpense = 'contra_expense';

    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::Asset, self::Expense, self::ContraLiability, self::ContraRevenue => true,
            self::Liability, self::Equity, self::Revenue, self::ContraAsset, self::ContraExpense => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Revenue => 'Revenue',
            self::Expense => 'Expense',
            self::ContraAsset => 'Contra Asset',
            self::ContraLiability => 'Contra Liability',
            self::ContraRevenue => 'Contra Revenue',
            self::ContraExpense => 'Contra Expense',
        };
    }
}
