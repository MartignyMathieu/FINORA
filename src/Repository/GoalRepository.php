<?php

namespace App\Repository;

use App\Entity\Goal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Goal>
 */
class GoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Goal::class);
    }

    /** @return list<Goal> */
    public function findAllByDeadline(User $owner): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('g.targetDate', 'ASC')
            ->addOrderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
