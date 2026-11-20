<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transfer>
 */
class TransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transfer::class);
    }

    /**
     * Virements du propriétaire, les plus récents d'abord.
     *
     * @return list<Transfer>
     */
    public function findForOwner(User $owner, int $limit = 300): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('f', 'a')
            ->join('t.fromAccount', 'f')
            ->join('t.toAccount', 'a')
            ->where('f.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Nombre de virements qui débitent ou créditent ce compte. */
    public function countForAccount(Account $account): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.fromAccount = :account OR t.toAccount = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
