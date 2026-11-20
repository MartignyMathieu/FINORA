<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\AccountShareRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/** Nombre d'invitations de partage en attente pour l'utilisateur connecté (pastille du menu, bandeau), calculé une fois par requête. */
final class SharedAccountsExtension
{
    private ?int $pending = null;

    public function __construct(
        private readonly AccountShareRepository $shares,
        private readonly Security $security,
    ) {
    }

    #[AsTwigFunction('pending_invitation_count')]
    public function pendingInvitationCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->pending ??= $this->shares->countPendingFor($user);
    }
}
