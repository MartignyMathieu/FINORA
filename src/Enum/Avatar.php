<?php

namespace App\Enum;

/** Pictogrammes proposés comme photo de profil (à défaut, l'initiale du prénom). */
enum Avatar: string
{
    case Rocket = 'rocket';
    case Smiley = 'smiley';
    case Star = 'star';
    case Leaf = 'leaf';
    case Cat = 'cat';
    case Dog = 'dog';
    case Crown = 'crown';
    case Planet = 'planet';
    case Flame = 'flame';
    case Bulb = 'bulb';

    public function label(): string
    {
        return match ($this) {
            self::Rocket => 'Fusée',
            self::Smiley => 'Sourire',
            self::Star => 'Étoile',
            self::Leaf => 'Feuille',
            self::Cat => 'Chat',
            self::Dog => 'Chien',
            self::Crown => 'Couronne',
            self::Planet => 'Planète',
            self::Flame => 'Flamme',
            self::Bulb => 'Idée',
        };
    }
}
