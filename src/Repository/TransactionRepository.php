<?php

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
