<?php

namespace App\Enum;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case Cash = 'cash';
    case Travel = 'travel';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Compte courant',
            self::Savings => 'Compte épargne',
            self::Cash => 'Espèces',
            self::Travel => 'Compte voyage',
            self::Other => 'Autre',
        };
    }

    public function icon(): Icon
    {
        return match ($this) {
            self::Checking => Icon::Card,
            self::Savings => Icon::Piggy,
            self::Cash => Icon::Cash,
            self::Travel => Icon::Plane,
            self::Other => Icon::Tag,
        };
    }
}
