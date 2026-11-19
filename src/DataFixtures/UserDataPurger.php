<?php

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Supprime un utilisateur et toutes ses données, pour que les fixtures puissent être rechargées. */
final class UserDataPurger
{
    public function purge(EntityManagerInterface $em, string $email): void
    {
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            return;
        }

        // Ordre imposé par les clés étrangères.
        $em->createQuery('DELETE FROM '.Transaction::class.' t WHERE t.account IN (SELECT a FROM '.Account::class.' a WHERE a.owner = :user)')->setParameter('user', $user)->execute();
        $em->createQuery('DELETE FROM '.RecurringTransaction::class.' r WHERE r.account IN (SELECT a FROM '.Account::class.' a WHERE a.owner = :user)')->setParameter('user', $user)->execute();
        $em->createQuery('DELETE FROM '.Budget::class.' b WHERE b.category IN (SELECT c FROM '.Category::class.' c WHERE c.owner = :user)')->setParameter('user', $user)->execute();
        foreach ([Goal::class, Category::class, Account::class] as $class) {
            $em->createQuery(sprintf('DELETE FROM %s e WHERE e.owner = :user', $class))->setParameter('user', $user)->execute();
        }
        // Les demandes de réinitialisation de mot de passe disparaissent avec l'utilisateur (ON DELETE CASCADE).
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.id = :id')->setParameter('id', $user->getId())->execute();
        $em->clear();
    }
}
