<?php

namespace App\Enum;

/** État d'une invitation de partage : en attente de réponse, ou acceptée (un refus supprime l'invitation). */
enum ShareStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Accepted => 'Acceptée',
        };
    }
}
