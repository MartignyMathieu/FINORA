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
use App\Enum\Icon;
use App\Enum\TransactionType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Agrégats affichés par le tableau de bord, les budgets et les statistiques.
 *
 * Toutes les données sont restreintes à l'utilisateur connecté.
 * Les totaux ne portent que sur les comptes dans la devise par défaut :
 * il n'y a pas de conversion entre devises.
 */
final class FinanceStats
{
    private const PALETTE = ['#1d6cf0', '#7dbcff', '#7c5cf0', '#f472b6'];
    private const OTHER_COLOR = '#cbd5e1';
    private const DONUT_RADIUS = 70;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        #[Autowire(param: 'app.default_currency')]
        private readonly string $currency,
    ) {
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /** Premier jour du mois demandé (« YYYY-MM »), ou du mois courant si absent ou invalide. */
    public static function resolveMonth(?string $value): \DateTimeImmutable
    {
        if (null !== $value && 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return new \DateTimeImmutable($value.'-01');
        }

        return new \DateTimeImmutable('first day of this month midnight');
    }

    /** Solde cumulé des comptes (solde initial + transactions) à la fin du mois donné. */
    public function totalBalance(\DateTimeImmutable $month): float
    {
        $initial = $this->em->createQuery(
            'SELECT COALESCE(SUM(a.initialBalance), 0) FROM '.Account::class.' a WHERE a.currency = :c AND a.owner = :user'
        )->setParameter('c', $this->currency)->setParameter('user', $this->user())->getSingleScalarResult();

        $net = $this->em->createQuery(
            'SELECT COALESCE(SUM(CASE WHEN t.type = :income THEN t.amount ELSE -t.amount END), 0)
             FROM '.Transaction::class.' t JOIN t.account a
             WHERE a.currency = :c AND a.owner = :user AND t.date <= :end'
        )
            ->setParameter('income', TransactionType::Income)
            ->setParameter('c', $this->currency)->setParameter('user', $this->user())
            ->setParameter('end', $this->endOf($month), Types::DATE_IMMUTABLE)
            ->getSingleScalarResult();

        return (float) $initial + (float) $net;
    }

    /** @return array{income: float, expense: float, savings: float} */
    public function monthTotals(\DateTimeImmutable $month): array
    {
        $rows = $this->em->createQuery(
            'SELECT t.type AS type, SUM(t.amount) AS total
             FROM '.Transaction::class.' t JOIN t.account a
             WHERE a.currency = :c AND a.owner = :user AND t.date BETWEEN :start AND :end
             GROUP BY t.type'
        )
            ->setParameter('c', $this->currency)->setParameter('user', $this->user())
            ->setParameter('start', $month, Types::DATE_IMMUTABLE)
            ->setParameter('end', $this->endOf($month), Types::DATE_IMMUTABLE)
            ->getArrayResult();

        $totals = [TransactionType::Income->value => 0.0, TransactionType::Expense->value => 0.0];
        foreach ($rows as $row) {
            $type = $row['type'] instanceof TransactionType ? $row['type']->value : (string) $row['type'];
            $totals[$type] = (float) $row['total'];
        }

        return [
            'income' => $totals['income'],
            'expense' => $totals['expense'],
            'savings' => $totals['income'] - $totals['expense'],
        ];
    }

    /** Variation du solde sur le mois, en pourcentage du solde d'ouverture (null si indéfini). */
    public function balanceVariation(\DateTimeImmutable $month): ?float
    {
        $opening = $this->totalBalance($month->modify('-1 month'));
        if (0.0 === $opening) {
            return null;
        }

        return ($this->totalBalance($month) - $opening) / abs($opening) * 100;
    }

    /** @return list<array{account: Account, balance: float}> */
    public function accountBalances(): array
    {
        $net = [];
        $rows = $this->em->createQuery(
            'SELECT a.id AS id, SUM(CASE WHEN t.type = :income THEN t.amount ELSE -t.amount END) AS net
             FROM '.Transaction::class.' t JOIN t.account a WHERE a.owner = :user GROUP BY a.id'
        )->setParameter('income', TransactionType::Income)->setParameter('user', $this->user())->getArrayResult();
        foreach ($rows as $row) {
            $net[$row['id']] = (float) $row['net'];
        }

        // Les virements déplacent l'argent d'un compte à l'autre : débit du compte source, crédit du compte cible.
        foreach ($this->em->createQuery(
            'SELECT IDENTITY(t.fromAccount) AS fromId, IDENTITY(t.toAccount) AS toId, t.amount AS amount
             FROM '.Transfer::class.' t JOIN t.fromAccount f WHERE f.owner = :user'
        )->setParameter('user', $this->user())->getArrayResult() as $transfer) {
            $net[$transfer['fromId']] = ($net[$transfer['fromId']] ?? 0.0) - (float) $transfer['amount'];
            $net[$transfer['toId']] = ($net[$transfer['toId']] ?? 0.0) + (float) $transfer['amount'];
        }

        $result = [];
        foreach ($this->em->getRepository(Account::class)->findBy(['owner' => $this->user()], ['id' => 'ASC']) as $account) {
            $result[] = [
                'account' => $account,
                'balance' => (float) $account->getInitialBalance() + ($net[$account->getId()] ?? 0.0),
            ];
        }

        return $result;
    }

    /**
     * Répartition du mois par catégorie, la plus importante d'abord.
     *
     * @return list<array{name: string, color: ?string, icon: ?Icon, total: float}>
     */
    public function totalsByCategory(\DateTimeImmutable $month, TransactionType $type): array
    {
        $rows = $this->em->createQuery(
            'SELECT c.name AS name, c.color AS color, c.icon AS icon, SUM(t.amount) AS total
             FROM '.Transaction::class.' t JOIN t.category c JOIN t.account a
             WHERE t.type = :type AND a.currency = :c AND a.owner = :user AND t.date BETWEEN :start AND :end
             GROUP BY c.id, c.name, c.color, c.icon
             ORDER BY total DESC, c.name ASC'
        )
            ->setParameter('type', $type)
            ->setParameter('c', $this->currency)->setParameter('user', $this->user())
            ->setParameter('start', $month, Types::DATE_IMMUTABLE)
            ->setParameter('end', $this->endOf($month), Types::DATE_IMMUTABLE)
            ->getArrayResult();

        return array_map(static fn (array $r): array => [
            'name' => $r['name'],
            'color' => $r['color'],
            'icon' => $r['icon'] instanceof Icon ? $r['icon'] : (null === $r['icon'] ? null : Icon::tryFrom((string) $r['icon'])),
            'total' => (float) $r['total'],
        ], $rows);
    }

    /**
     * Données du donut : 4 catégories principales, le reste regroupé dans « Autres ».
     *
     * @param list<array{name: string, color: ?string, icon: ?Icon, total: float}> $rows
     *
     * @return array{total: float, items: list<array{name: string, color: string, total: float, percent: int}>, segments: list<array{color: string, dash: float, gap: float, offset: float}>, circumference: float, radius: int}
     */
    public function donut(array $rows): array
    {
        $total = array_sum(array_column($rows, 'total'));
        $top = array_slice($rows, 0, 4);
        $rest = array_slice($rows, 4);

        $items = [];
        foreach ($top as $i => $row) {
            $items[] = ['name' => $row['name'], 'color' => $row['color'] ?: self::PALETTE[$i], 'total' => $row['total']];
        }
        if ([] !== $rest) {
            $items[] = ['name' => 'Autres', 'color' => self::OTHER_COLOR, 'total' => array_sum(array_column($rest, 'total'))];
        }

        $circumference = 2 * M_PI * self::DONUT_RADIUS;
        $segments = [];
        $offset = 0.0;
        foreach ($items as &$item) {
            $share = $total > 0 ? $item['total'] / $total : 0.0;
            $item['percent'] = (int) round($share * 100);
            $length = $share * $circumference;
            $segments[] = [
                'color' => $item['color'],
                'dash' => max(0.0, $length - 1.5),
                'gap' => $circumference - max(0.0, $length - 1.5),
                'offset' => -$offset,
            ];
            $offset += $length;
        }
        unset($item);

        return [
            'total' => (float) $total,
            'items' => $items,
            'segments' => $segments,
            'circumference' => $circumference,
            'radius' => self::DONUT_RADIUS,
        ];
    }

    /**
     * Revenus et dépenses des $count derniers mois (le mois donné inclus), du plus ancien au plus récent.
     *
     * @return list<array{month: \DateTimeImmutable, income: float, expense: float}>
     */
    public function history(\DateTimeImmutable $month, int $count = 6): array
    {
        $first = $month->modify(sprintf('-%d months', $count - 1));

        $rows = $this->em->createQuery(
            'SELECT t.type AS type, t.date AS date, t.amount AS amount
             FROM '.Transaction::class.' t JOIN t.account a
             WHERE a.currency = :c AND a.owner = :user AND t.date BETWEEN :start AND :end'
        )
            ->setParameter('c', $this->currency)->setParameter('user', $this->user())
            ->setParameter('start', $first, Types::DATE_IMMUTABLE)
            ->setParameter('end', $this->endOf($month), Types::DATE_IMMUTABLE)
            ->getResult();

        $history = [];
        for ($i = 0; $i < $count; ++$i) {
            $history[$first->modify(sprintf('+%d months', $i))->format('Y-m')] = ['income' => 0.0, 'expense' => 0.0];
        }
        foreach ($rows as $row) {
            $type = $row['type'] instanceof TransactionType ? $row['type'] : TransactionType::from((string) $row['type']);
            $key = $row['date']->format('Y-m');
            if (isset($history[$key])) {
                $history[$key][TransactionType::Income === $type ? 'income' : 'expense'] += (float) $row['amount'];
            }
        }

        $result = [];
        foreach ($history as $key => $values) {
            $result[] = ['month' => new \DateTimeImmutable($key.'-01')] + $values;
        }

        return $result;
    }

    /**
     * Géométrie du graphique en courbes (viewBox 0 0 560 250).
     *
     * @param list<array{month: \DateTimeImmutable, income: float, expense: float}> $history
     *
     * @return array<string, mixed>
     */
    public function lineChart(array $history): array
    {
        $left = 56;
        $right = 548;
        $top = 16;
        $bottom = 210;

        $max = max(1.0, ...array_map(static fn (array $h): float => max($h['income'], $h['expense']), $history));
        $step = 500;
        foreach ([50, 100, 250, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000] as $candidate) {
            $step = $candidate;
            if ($max / $candidate <= 6) {
                break;
            }
        }
        $ceiling = max($step, (int) ceil($max / $step) * $step);

        $y = static fn (float $v): float => $bottom - ($v / $ceiling) * ($bottom - $top);
        $count = count($history);
        $x = static fn (int $i): float => $count > 1 ? $left + 14 + $i * (($right - $left - 28) / ($count - 1)) : ($left + $right) / 2;

        $ticks = [];
        for ($v = 0; $v <= $ceiling; $v += $step) {
            $ticks[] = ['y' => round($y((float) $v), 1), 'label' => number_format($v, 0, ',', "\u{202F}")];
        }

        $series = [];
        foreach (['income', 'expense'] as $key) {
            $points = [];
            foreach ($history as $i => $h) {
                $points[] = ['x' => round($x($i), 1), 'y' => round($y($h[$key]), 1)];
            }
            $line = implode(' ', array_map(static fn (array $p): string => $p['x'].','.$p['y'], $points));
            $series[$key] = [
                'points' => $points,
                'line' => $line,
                'area' => sprintf('%s,%d %s %s,%d', $points[0]['x'], $bottom, $line, $points[$count - 1]['x'], $bottom),
            ];
        }

        // Une zone sensible par mois (survol de la courbe) : de la mi-distance avec le mois précédent à la mi-distance
        // avec le suivant, pour qu'aucune zone ne laisse de trou ni ne chevauche sa voisine.
        $labels = [];
        foreach ($history as $i => $h) {
            $center = $x($i);
            $start = $i > 0 ? ($x($i - 1) + $center) / 2 : $left;
            $end = $i < $count - 1 ? ($center + $x($i + 1)) / 2 : $right;
            $labels[] = [
                'x' => round($center, 1),
                'month' => $h['month'],
                'income' => $h['income'],
                'expense' => $h['expense'],
                'hitX' => round($start, 1),
                'hitWidth' => round($end - $start, 1),
            ];
        }

        return ['left' => $left, 'right' => $right, 'top' => $top, 'bottom' => $bottom, 'ticks' => $ticks, 'series' => $series, 'labels' => $labels];
    }

    /**
     * Budgets du mois avec les dépenses correspondantes.
     *
     * @return list<array{budget: Budget, spent: float, percent: int, level: string}>
     */
    public function budgetProgress(\DateTimeImmutable $month): array
    {
        $spentRows = $this->em->createQuery(
            'SELECT c.id AS cid, a.currency AS currency, SUM(t.amount) AS total
             FROM '.Transaction::class.' t JOIN t.category c JOIN t.account a
             WHERE t.type = :expense AND a.owner = :user AND t.date BETWEEN :start AND :end
             GROUP BY c.id, a.currency'
        )
            ->setParameter('expense', TransactionType::Expense)
            ->setParameter('user', $this->user())
            ->setParameter('start', $month, Types::DATE_IMMUTABLE)
            ->setParameter('end', $this->endOf($month), Types::DATE_IMMUTABLE)
            ->getArrayResult();
        $spentBy = [];
        foreach ($spentRows as $row) {
            $spentBy[$row['cid']][$row['currency']] = (float) $row['total'];
        }

        $budgets = $this->em->createQuery(
            'SELECT b, c FROM '.Budget::class.' b JOIN b.category c WHERE b.month = :month AND c.owner = :user ORDER BY c.name ASC'
        )->setParameter('month', $month, Types::DATE_IMMUTABLE)->setParameter('user', $this->user())->getResult();

        $result = [];
        foreach ($budgets as $budget) {
            $spent = $spentBy[$budget->getCategory()->getId()][$budget->getCurrency()] ?? 0.0;
            $limit = (float) $budget->getAmount();
            $percent = $limit > 0 ? (int) round($spent / $limit * 100) : 0;
            $result[] = [
                'budget' => $budget,
                'spent' => $spent,
                'percent' => $percent,
                'level' => $percent > 100 ? 'over' : ($percent >= 80 ? 'warn' : 'ok'),
            ];
        }

        return $result;
    }

    /**
     * Nombre d'éléments de chaque type appartenant à l'utilisateur (récapitulatif du profil).
     *
     * @return array{accounts: int, categories: int, transactions: int, recurring: int, budgets: int, goals: int}
     */
    public function counts(): array
    {
        $count = fn (string $dql): int => (int) $this->em->createQuery($dql)->setParameter('user', $this->user())->getSingleScalarResult();

        return [
            'accounts' => $count('SELECT COUNT(a.id) FROM '.Account::class.' a WHERE a.owner = :user'),
            'categories' => $count('SELECT COUNT(c.id) FROM '.Category::class.' c WHERE c.owner = :user'),
            'transactions' => $count('SELECT COUNT(t.id) FROM '.Transaction::class.' t JOIN t.account a WHERE a.owner = :user'),
            'recurring' => $count('SELECT COUNT(r.id) FROM '.RecurringTransaction::class.' r JOIN r.account a WHERE a.owner = :user'),
            'budgets' => $count('SELECT COUNT(b.id) FROM '.Budget::class.' b JOIN b.category c WHERE c.owner = :user'),
            'goals' => $count('SELECT COUNT(g.id) FROM '.Goal::class.' g WHERE g.owner = :user'),
        ];
    }

    /**
     * Revenus et dépenses récurrents ramenés à un équivalent mensuel (règles actives non terminées,
     * comptes dans la devise par défaut).
     *
     * @return array{income: float, expense: float}
     */
    public function recurringMonthly(): array
    {
        $totals = ['income' => 0.0, 'expense' => 0.0];
        foreach ($this->em->getRepository(RecurringTransaction::class)->findByOwner($this->user()) as $rule) {
            if (!$rule->isActive() || $rule->isEnded() || $rule->getAccount()?->getCurrency() !== $this->currency) {
                continue;
            }
            $totals[$rule->getType()->value] += $rule->getMonthlyAmount();
        }

        return $totals;
    }

    /**
     * Dernières transactions du mois (les plus récentes d'abord).
     *
     * @return list<Transaction>
     */
    public function recentTransactions(\DateTimeImmutable $month, int $limit = 5): array
    {
        return $this->em->createQuery(
            'SELECT t, c, a FROM '.Transaction::class.' t JOIN t.category c JOIN t.account a
             WHERE a.owner = :user AND t.date BETWEEN :start AND :end
             ORDER BY t.date DESC, t.id DESC'
        )
            ->setParameter('user', $this->user())
            ->setParameter('start', $month, Types::DATE_IMMUTABLE)
            ->setParameter('end', $this->endOf($month), Types::DATE_IMMUTABLE)
            ->setMaxResults($limit)
            ->getResult();
    }

    private function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Les statistiques nécessitent un utilisateur connecté.');
        }

        return $user;
    }

    private function endOf(\DateTimeImmutable $month): \DateTimeImmutable
    {
        return $month->modify('last day of this month');
    }
}
