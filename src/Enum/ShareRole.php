<?php

namespace App\Enum;

/** Droits donnés à une personne invitée sur un compte partagé. */
enum ShareRole: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Lecture seule',
            self::Editor => 'Lecture et saisie',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Viewer => 'Peut consulter le compte et ses transactions.',
            self::Editor => 'Peut aussi ajouter, modifier et supprimer des transactions.',
        };
    }
}
