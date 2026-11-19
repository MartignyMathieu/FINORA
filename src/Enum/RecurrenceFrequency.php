<?php

namespace App\Enum;

enum RecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Quotidienne',
            self::Weekly => 'Hebdomadaire',
            self::Monthly => 'Mensuelle',
            self::Yearly => 'Annuelle',
        };
    }
}
