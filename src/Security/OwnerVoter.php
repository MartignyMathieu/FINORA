<?php

namespace App\Security;

use App\Entity\OwnedInterface;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Seul le propriétaire peut consulter, modifier ou supprimer une donnée.
 *
 * @extends Voter<string, OwnedInterface>
 */
final class OwnerVoter extends Voter
{
    public const MANAGE = 'MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof OwnedInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $owner = $subject->getOwner();

        return $user instanceof User && null !== $owner && $owner->getId() === $user->getId();
    }
}
