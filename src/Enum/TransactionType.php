<?php

namespace App\Enum;

enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Revenu',
            self::Expense => 'Dépense',
        };
    }
}
