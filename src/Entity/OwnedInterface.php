<?php

namespace App\Entity;

/** Donnée appartenant à un utilisateur (directement, ou via son compte / sa catégorie). */
interface OwnedInterface
{
    public function getOwner(): ?User;
}
