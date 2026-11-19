<?php

namespace App\Service;

use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionType;
use App\Repository\RecurringTransactionRepository;
use App\Repository\TransactionRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Données de la vue mensuelle du calendrier : transactions de chaque jour et échéances récurrentes à venir.
 *
 * Les montants affichés dans la grille et les totaux ne portent que sur les comptes dans la devise par
 * défaut (pas de conversion) ; le détail d'un jour liste tout, dans la devise de chaque compte.
 *
 * @phpstan-type Day array{
 *     date: \DateTimeImmutable, inMonth: bool, today: bool, selected: bool,
 *     income: float, expense: float, plannedIncome: float, plannedExpense: float,
 *     count: int, plannedCount: int
 * }
 * @phpstan-type Planned array{rule: RecurringTransaction, date: \DateTimeImmutable}
 */
final class CalendarBuilder
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly RecurringTransactionRepository $rules,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.default_currency')]
        private readonly string $currency,
    ) {
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /** Le jour demandé (« AAAA-MM-JJ ») s'il est valide et dans le mois affiché, sinon aujourd'hui si on est dans ce mois, sinon null. */
    public function resolveSelectedDay(\DateTimeImmutable $month, ?string $value): ?\DateTimeImmutable
    {
        $first = $month->modify('first day of this month')->setTime(0, 0);

        if (null !== $value) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (false !== $day && $day->format('Y-m-d') === $value && $day->format('Y-m') === $first->format('Y-m')) {
                return $day;
            }
        }

        $today = $this->clock->now()->setTime(0, 0);

        return $today->format('Y-m') === $first->format('Y-m') ? $today : null;
    }

    /**
     * @return array{
     *     month: \DateTimeImmutable,
     *     weeks: list<list<Day>>,
     *     totals: array{income: float, expense: float, net: float, plannedIncome: float, plannedExpense: float},
     *     selected: ?array{date: \DateTimeImmutable, transactions: list<Transaction>, planned: list<Planned>}
     * }
     */
    public function build(User $user, \DateTimeImmutable $month, ?\DateTimeImmutable $selected = null): array
    {
        $first = $month->modify('first day of this month')->setTime(0, 0);
        $last = $first->modify('last day of this month');
        $today = $this->clock->now()->setTime(0, 0);

        /** @var array<string, list<Transaction>> $transactionsByDay */
        $transactionsByDay = [];
        foreach ($this->transactions->findBetween($user, $first, $last) as $transaction) {
            $transactionsByDay[$transaction->getDate()->format('Y-m-d')][] = $transaction;
        }

        /** @var array<string, list<Planned>> $plannedByDay */
        $plannedByDay = [];
        foreach ($this->rules->findByOwner($user) as $rule) {
            if (!$rule->isActive()) {
                continue;
            }
            // Échéances pas encore transformées en transactions, jusqu'à la fin du mois affiché.
            foreach ($rule->getDueDates($last) as $date) {
                if ($date >= $first) {
                    $plannedByDay[$date->format('Y-m-d')][] = ['rule' => $rule, 'date' => $date];
                }
            }
        }

        // Grille : semaines complètes, du lundi au dimanche.
        $gridStart = $first->modify(sprintf('-%d days', (int) $first->format('N') - 1));
        $gridEnd = $last->modify(sprintf('+%d days', 7 - (int) $last->format('N')));

        $totals = ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0, 'plannedIncome' => 0.0, 'plannedExpense' => 0.0];
        $weeks = [];
        $week = [];
        for ($date = $gridStart; $date <= $gridEnd; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $inMonth = $date >= $first && $date <= $last;
            $day = [
                'date' => $date,
                'inMonth' => $inMonth,
                'today' => $date == $today,
                'selected' => null !== $selected && $date == $selected,
                'income' => 0.0, 'expense' => 0.0, 'plannedIncome' => 0.0, 'plannedExpense' => 0.0,
                'count' => 0, 'plannedCount' => 0,
            ];

            if ($inMonth) {
                foreach ($transactionsByDay[$key] ?? [] as $transaction) {
                    ++$day['count'];
                    if ($transaction->getAccount()?->getCurrency() !== $this->currency) {
                        continue;
                    }
                    $day[TransactionType::Income === $transaction->getType() ? 'income' : 'expense'] += (float) $transaction->getAmount();
                }
                foreach ($plannedByDay[$key] ?? [] as $planned) {
                    ++$day['plannedCount'];
                    if ($planned['rule']->getAccount()?->getCurrency() !== $this->currency) {
                        continue;
                    }
                    $day[TransactionType::Income === $planned['rule']->getType() ? 'plannedIncome' : 'plannedExpense'] += (float) $planned['rule']->getAmount();
                }
                $totals['income'] += $day['income'];
                $totals['expense'] += $day['expense'];
                $totals['plannedIncome'] += $day['plannedIncome'];
                $totals['plannedExpense'] += $day['plannedExpense'];
            }

            $week[] = $day;
            if (7 === \count($week)) {
                $weeks[] = $week;
                $week = [];
            }
        }
        $totals['net'] = $totals['income'] - $totals['expense'];

        $detail = null;
        if (null !== $selected && $selected >= $first && $selected <= $last) {
            $key = $selected->format('Y-m-d');
            $detail = [
                'date' => $selected,
                'transactions' => $transactionsByDay[$key] ?? [],
                'planned' => $plannedByDay[$key] ?? [],
            ];
        }

        return ['month' => $first, 'weeks' => $weeks, 'totals' => $totals, 'selected' => $detail];
    }
}
