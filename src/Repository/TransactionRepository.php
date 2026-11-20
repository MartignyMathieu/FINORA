<?php

namespace App\Repository;

use App\Dto\TransactionFilter;
use App\Entity\Account;
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
     * Transactions les plus récentes d'abord, selon les filtres (type, texte, période, montants, compte, catégorie).
     * Limitées à quelques centaines : pour tout récupérer, voir iterateForExport().
     *
     * @return list<Transaction>
     */
    public function search(User $owner, ?TransactionFilter $filter = null): array
    {
        return $this->filtered($owner, $filter ?? new TransactionFilter())
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();
    }

    /**
     * Transactions du propriétaire parmi les identifiants donnés (les autres sont ignorées en silence : jamais celles d'un autre utilisateur).
     *
     * @param list<int> $ids
     *
     * @return list<Transaction>
     */
    public function findOwnedByIds(User $owner, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->addSelect('c', 'a')
            ->join('t.category', 'c')
            ->join('t.account', 'a')
            ->where('a.owner = :owner')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('owner', $owner)
            ->setParameter('ids', $ids)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Dernières transactions d'un compte (les plus récentes d'abord), avec leur catégorie et la personne qui les a saisies.
     *
     * @return list<Transaction>
     */
    public function findLatestForAccount(Account $account, int $limit = 300): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('c', 'u')
            ->join('t.category', 'c')
            ->leftJoin('t.createdBy', 'u')
            ->where('t.account = :account')
            ->setParameter('account', $account)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Nombre maximal de lignes de la liste (au-delà, l'export CSV contient tout). */
    public function limit(): int
    {
        return self::LIMIT;
    }

    /**
     * Toutes les transactions correspondant aux filtres, de la plus ancienne à la plus récente, sans limite
     * de nombre (lues au fil de l'eau). Les bornes de dates sont incluses.
     *
     * @return iterable<Transaction>
     */
    public function iterateForExport(User $owner, ?TransactionFilter $filter = null): iterable
    {
        return $this->filtered($owner, $filter ?? new TransactionFilter())
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->toIterable();
    }

    /**
     * Totaux de toutes les transactions correspondant aux filtres (pas seulement les lignes affichées) : nombre total,
     * revenus et dépenses dans la devise demandée. Les comptes d'une autre devise comptent dans le nombre, pas dans les sommes.
     *
     * @return array{count: int, income: float, expense: float}
     */
    public function totals(User $owner, TransactionFilter $filter, string $currency): array
    {
        $count = (int) $this->filtered($owner, $filter)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $rows = $this->filtered($owner, $filter)
            ->select('t.type AS type, SUM(t.amount) AS total')
            ->andWhere('a.currency = :currency')
            ->setParameter('currency', $currency)
            ->groupBy('t.type')
            ->getQuery()
            ->getArrayResult();

        $sums = [TransactionType::Income->value => 0.0, TransactionType::Expense->value => 0.0];
        foreach ($rows as $row) {
            $type = $row['type'] instanceof TransactionType ? $row['type']->value : (string) $row['type'];
            $sums[$type] = (float) $row['total'];
        }

        return ['count' => $count, 'income' => $sums['income'], 'expense' => $sums['expense']];
    }

    /** Transactions du propriétaire, avec compte et catégorie, selon les filtres. */
    private function filtered(User $owner, TransactionFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('c', 'a')
            ->join('t.category', 'c')
            ->join('t.account', 'a')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner);

        if (null !== ($type = $filter->transactionType())) {
            $qb->andWhere('t.type = :type')->setParameter('type', $type);
        }
        if ('' !== ($text = $filter->searchText())) {
            $qb->andWhere('LOWER(t.label) LIKE :text OR LOWER(c.name) LIKE :text')
                ->setParameter('text', '%'.mb_strtolower(addcslashes($text, '%_\\')).'%');
        }
        if (null !== ($from = $filter->fromDate())) {
            $qb->andWhere('t.date >= :from')->setParameter('from', $from, Types::DATE_IMMUTABLE);
        }
        if (null !== ($to = $filter->toDate())) {
            $qb->andWhere('t.date <= :to')->setParameter('to', $to, Types::DATE_IMMUTABLE);
        }
        if (null !== ($min = $filter->minAmount())) {
            $qb->andWhere('t.amount >= :min')->setParameter('min', $min);
        }
        if (null !== ($max = $filter->maxAmount())) {
            $qb->andWhere('t.amount <= :max')->setParameter('max', $max);
        }
        // Un compte ou une catégorie d'un autre utilisateur ne donne aucun résultat : le propriétaire est déjà imposé ci-dessus.
        if (null !== ($account = $filter->accountId())) {
            $qb->andWhere('a.id = :account')->setParameter('account', $account);
        }
        if (null !== ($category = $filter->categoryId())) {
            $qb->andWhere('c.id = :category')->setParameter('category', $category);
        }

        return $qb;
    }
}
