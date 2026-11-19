<?php

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    private const LIMIT = 300;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Transactions du propriétaire entre deux dates incluses, par date puis par identifiant.
     *
     * @return list<Transaction>
     */
    public function findBetween(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('c', 'a')
            ->join('t.category', 'c')
            ->join('t.account', 'a')
            ->where('a.owner = :owner')
            ->andWhere('t.date BETWEEN :from AND :to')
            ->setParameter('owner', $owner)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Transactions les plus récentes d'abord, filtrées par type et par texte (libellé ou catégorie).
     *
     * @return list<Transaction>
     */
    public function search(User $owner, ?TransactionType $type = null, string $text = ''): array
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('c', 'a')
            ->join('t.category', 'c')
            ->join('t.account', 'a')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(self::LIMIT);

        if (null !== $type) {
            $qb->andWhere('t.type = :type')->setParameter('type', $type);
        }
        if ('' !== $text) {
            $qb->andWhere('LOWER(t.label) LIKE :text OR LOWER(c.name) LIKE :text')
                ->setParameter('text', '%'.mb_strtolower(addcslashes($text, '%_\\')).'%');
        }

        return $qb->getQuery()->getResult();
    }
}
