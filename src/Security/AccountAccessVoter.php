<?php

namespace App\Security;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\AccountShareRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Accès à un compte (ou à l'une de ses transactions) pour son propriétaire et pour les personnes avec qui il est partagé :
 * VIEW (consulter) pour tout accès accepté, EDIT (saisir, modifier, supprimer des transactions) pour le niveau « Lecture et saisie ».
 * La gestion du compte lui-même (renommer, supprimer, partager) reste au propriétaire seul (voir OwnerVoter).
 *
 * @extends Voter<string, Account|Transaction>
 */
final class AccountAccessVoter extends Voter
{
    public const VIEW = 'ACCOUNT_VIEW';
    public const EDIT = 'ACCOUNT_EDIT';

    public function __construct(private readonly AccountShareRepository $shares)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true) && ($subject instanceof Account || $subject instanceof Transaction);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $account = $subject instanceof Transaction ? $subject->getAccount() : $subject;
        if (!$user instanceof User || null === $account) {
            return false;
        }

        if ($account->getOwner()?->getId() === $user->getId()) {
            return true;
        }

        $share = $this->shares->findAccepted($account, $user);

        return null !== $share && (self::VIEW === $attribute || $share->canEdit());
    }
}
