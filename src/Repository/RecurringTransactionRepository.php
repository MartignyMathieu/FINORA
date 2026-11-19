<?php

namespace App\Repository;

use App\Entity\RecurringTransaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringTransaction>
 */
class RecurringTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringTransaction::class);
    }

    /** @return list<RecurringTransaction> Règles du propriétaire, avec leur compte et leur catégorie. */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('a', 'c')
            ->join('r.account', 'a')
            ->join('r.category', 'c')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('r.label', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<RecurringTransaction> Règles actives de tous les utilisateurs. */
    public function findActive(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.active = true')
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
