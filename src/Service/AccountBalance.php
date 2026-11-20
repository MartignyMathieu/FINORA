<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Enum\TransactionType;
use Doctrine\ORM\EntityManagerInterface;

/** Solde d'un seul compte : solde initial, transactions, et virements reçus ou émis (utilisé pour les comptes partagés). */
final class AccountBalance
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function of(Account $account): float
    {
        $net = (float) $this->em->createQuery(
            'SELECT COALESCE(SUM(CASE WHEN t.type = :income THEN t.amount ELSE -t.amount END), 0) FROM '.Transaction::class.' t WHERE t.account = :account'
        )->setParameter('income', TransactionType::Income)->setParameter('account', $account)->getSingleScalarResult();

        $in = (float) $this->em->createQuery('SELECT COALESCE(SUM(t.amount), 0) FROM '.Transfer::class.' t WHERE t.toAccount = :account')
            ->setParameter('account', $account)->getSingleScalarResult();
        $out = (float) $this->em->createQuery('SELECT COALESCE(SUM(t.amount), 0) FROM '.Transfer::class.' t WHERE t.fromAccount = :account')
            ->setParameter('account', $account)->getSingleScalarResult();

        return (float) $account->getInitialBalance() + $net + $in - $out;
    }
}
