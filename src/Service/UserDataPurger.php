<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Supprime un utilisateur et toutes ses données (suppression de compte, rechargement des fixtures).
 *
 * Suppression définitive : tout se fait dans une seule transaction, tout ou rien.
 */
final class UserDataPurger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function purgeByEmail(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user instanceof User) {
            $this->purge($user);
        }
    }

    public function purge(User $user): void
    {
        $userId = $user->getId();

        $this->em->wrapInTransaction(function () use ($user, $userId): void {
            // Ordre imposé par les clés étrangères.
            $this->em->createQuery('DELETE FROM '.Transaction::class.' t WHERE t.account IN (SELECT a FROM '.Account::class.' a WHERE a.owner = :user)')->setParameter('user', $user)->execute();
            $this->em->createQuery('DELETE FROM '.Transfer::class.' t WHERE t.fromAccount IN (SELECT a FROM '.Account::class.' a WHERE a.owner = :user)')->setParameter('user', $user)->execute();
            $this->em->createQuery('DELETE FROM '.RecurringTransaction::class.' r WHERE r.account IN (SELECT a FROM '.Account::class.' a WHERE a.owner = :user)')->setParameter('user', $user)->execute();
            $this->em->createQuery('DELETE FROM '.Budget::class.' b WHERE b.category IN (SELECT c FROM '.Category::class.' c WHERE c.owner = :user)')->setParameter('user', $user)->execute();
            foreach ([Goal::class, Category::class, Account::class] as $class) {
                $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.owner = :user', $class))->setParameter('user', $user)->execute();
            }
            // Les demandes de réinitialisation de mot de passe disparaissent avec l'utilisateur (ON DELETE CASCADE).
            $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.id = :id')->setParameter('id', $userId)->execute();
        });

        $this->em->clear();
    }
}
