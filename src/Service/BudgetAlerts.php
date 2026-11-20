<?php

namespace App\Service;

use App\Entity\Budget;
use Symfony\Component\Clock\ClockInterface;

/**
 * Alertes des budgets du mois en cours : un budget proche de son plafond (80 % et plus), atteint ou dépassé.
 *
 * @phpstan-type Alert array{budget: Budget, spent: float, limit: float, percent: int, level: string}
 */
final class BudgetAlerts
{
    public const WARNING = 'warn';
    public const FULL = 'full';
    public const OVER = 'over';

    /** Seuil d'avertissement : part du plafond à partir de laquelle on prévient. */
    public const THRESHOLD = 0.8;

    public function __construct(
        private readonly FinanceStats $stats,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Budgets du mois en cours à surveiller, du plus grave au moins grave (dépassés, puis atteints, puis proches du plafond).
     *
     * @return list<Alert>
     */
    public function current(): array
    {
        $month = $this->clock->now()->modify('first day of this month')->setTime(0, 0);

        $alerts = [];
        foreach ($this->stats->budgetProgress($month) as $row) {
            $limit = (float) $row['budget']->getAmount();
            if ($limit <= 0) {
                continue;
            }
            $spent = $row['spent'];
            $level = match (true) {
                round($spent, 2) > round($limit, 2) => self::OVER,
                round($spent, 2) === round($limit, 2) => self::FULL,
                $spent >= $limit * self::THRESHOLD => self::WARNING,
                default => null,
            };
            if (null !== $level) {
                $alerts[] = ['budget' => $row['budget'], 'spent' => $spent, 'limit' => $limit, 'percent' => $row['percent'], 'level' => $level];
            }
        }

        $rank = [self::OVER => 0, self::FULL => 1, self::WARNING => 2];
        usort($alerts, static fn (array $a, array $b): int => [$rank[$a['level']], -$a['percent']] <=> [$rank[$b['level']], -$b['percent']]);

        return $alerts;
    }

    /**
     * Empreinte des alertes en cours : elle change dès qu'un budget change de niveau, ce qui permet de ne plus afficher
     * une bannière fermée tant que rien de nouveau ne se produit.
     *
     * @param list<Alert> $alerts
     */
    public static function signature(array $alerts): string
    {
        return implode(',', array_map(static fn (array $alert): string => $alert['budget']->getId().':'.$alert['level'], $alerts));
    }
}
