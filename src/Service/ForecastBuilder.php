<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionType;
use App\Repository\RecurringTransactionRepository;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prévision du solde à la fin du mois en cours.
 *
 * Point de départ : le solde d'aujourd'hui (comptes dans la devise par défaut, transactions datées d'aujourd'hui ou avant).
 * On y ajoute, jusqu'à la fin du mois : les transactions déjà saisies avec une date future, et les échéances de récurrences
 * pas encore transformées en transactions. Les virements entre comptes ne changent pas le solde total. Aucune conversion
 * entre devises : les comptes en autre devise sont ignorés, comme sur le reste du tableau de bord.
 *
 * @phpstan-type Event array{date: \DateTimeImmutable, label: string, amount: float, planned: bool}
 */
final class ForecastBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $transactions,
        private readonly RecurringTransactionRepository $rules,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.default_currency')]
        private readonly string $currency,
    ) {
    }

    /**
     * @return array{
     *     today: \DateTimeImmutable, endOfMonth: \DateTimeImmutable, currency: string,
     *     balanceNow: float, upcomingIncome: float, upcomingExpense: float, projected: float,
     *     lowest: float, lowestDate: ?\DateTimeImmutable, negative: bool,
     *     events: list<Event>
     * }
     */
    public function build(User $user): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $end = $today->modify('last day of this month');

        $balanceNow = $this->balanceAt($user, $today);

        $events = [];
        // Transactions déjà saisies avec une date postérieure à aujourd'hui.
        foreach ($this->transactions->findBetween($user, $today->modify('+1 day'), $end) as $transaction) {
            if ($transaction->getAccount()?->getCurrency() !== $this->currency) {
                continue;
            }
            $events[] = [
                'date' => $transaction->getDate(),
                'label' => (string) $transaction->getLabel(),
                'amount' => $this->signed($transaction),
                'planned' => false,
            ];
        }
        // Échéances de récurrences pas encore générées (y compris celles du jour ou en retard de génération).
        foreach ($this->rules->findByOwner($user) as $rule) {
            if (!$rule->isActive() || $rule->getAccount()?->getCurrency() !== $this->currency) {
                continue;
            }
            foreach ($rule->getDueDates($end) as $date) {
                $events[] = [
                    'date' => $date < $today ? $today : $date,
                    'label' => (string) $rule->getLabel(),
                    'amount' => (TransactionType::Income === $rule->getType() ? 1 : -1) * (float) $rule->getAmount(),
                    'planned' => true,
                ];
            }
        }
        usort($events, static fn (array $a, array $b): int => [$a['date'], $a['amount']] <=> [$b['date'], $b['amount']]);

        $income = $expense = 0.0;
        $running = $balanceNow;
        $lowest = $balanceNow;
        $lowestDate = null;
        foreach ($events as $event) {
            $running += $event['amount'];
            $event['amount'] > 0 ? $income += $event['amount'] : $expense -= $event['amount'];
            if ($running < $lowest) {
                $lowest = $running;
                $lowestDate = $event['date'];
            }
        }

        return [
            'today' => $today,
            'endOfMonth' => $end,
            'currency' => $this->currency,
            'balanceNow' => $balanceNow,
            'upcomingIncome' => $income,
            'upcomingExpense' => $expense,
            'projected' => $running,
            'lowest' => $lowest,
            'lowestDate' => $lowestDate,
            'negative' => $lowest < 0 && null !== $lowestDate,
            'events' => $events,
        ];
    }

    /** Solde des comptes en devise par défaut à la fin de la journée donnée. */
    private function balanceAt(User $user, \DateTimeImmutable $day): float
    {
        $initial = $this->em->createQuery(
            'SELECT COALESCE(SUM(a.initialBalance), 0) FROM '.Account::class.' a WHERE a.currency = :c AND a.owner = :user'
        )->setParameter('c', $this->currency)->setParameter('user', $user)->getSingleScalarResult();

        $net = $this->em->createQuery(
            'SELECT COALESCE(SUM(CASE WHEN t.type = :income THEN t.amount ELSE -t.amount END), 0)
             FROM '.Transaction::class.' t JOIN t.account a
             WHERE a.currency = :c AND a.owner = :user AND t.date <= :day'
        )
            ->setParameter('income', TransactionType::Income)
            ->setParameter('c', $this->currency)
            ->setParameter('user', $user)
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->getSingleScalarResult();

        return (float) $initial + (float) $net;
    }

    private function signed(Transaction $transaction): float
    {
        return (TransactionType::Income === $transaction->getType() ? 1 : -1) * (float) $transaction->getAmount();
    }
}
