<?php

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
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
     * Limitées à quelques centaines : pour tout récupérer, voir iterateForExport().
     *
     * @return list<Transaction>
     */
    public function search(User $owner, ?TransactionType $type = null, string $text = ''): array
    {
        return $this->filtered($owner, $type, $text)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les transactions correspondant aux filtres, de la plus ancienne à la plus récente, sans limite
     * de nombre (lues au fil de l'eau). Les bornes de dates sont incluses.
     *
     * @return iterable<Transaction>
     */
    public function iterateForExport(
        User $owner,
        ?TransactionType $type = null,
        string $text = '',
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): iterable {
        $qb = $this->filtered($owner, $type, $text)
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        if (null !== $from) {
            $qb->andWhere('t.date >= :from')->setParameter('from', $from, Types::DATE_IMMUTABLE);
        }
        if (null !== $to) {
            $qb->andWhere('t.date <= :to')->setParameter('to', $to, Types::DATE_IMMUTABLE);
        }

        return $qb->getQuery()->toIterable();
    }

    /** Transactions du propriétaire, avec compte et catégorie, filtrées par type et par texte. */
    private function filtered(User $owner, ?TransactionType $type, string $text): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('c', 'a')
            ->join('t.category', 'c')
            ->join('t.account', 'a')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner);

        if (null !== $type) {
            $qb->andWhere('t.type = :type')->setParameter('type', $type);
        }
        if ('' !== $text) {
            $qb->andWhere('LOWER(t.label) LIKE :text OR LOWER(c.name) LIKE :text')
                ->setParameter('text', '%'.mb_strtolower(addcslashes($text, '%_\\')).'%');
        }

        return $qb;
    }
}
