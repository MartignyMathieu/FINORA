<?php

namespace App\Tests\Controller;

use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use App\Service\ForecastBuilder;
use Symfony\Component\Clock\MockClock;

/** Prévision du solde à la fin du mois : calcul (horloge figée au 20 novembre 2026) et carte de l'accueil. */
final class ForecastTest extends AbstractCrudTestCase
{
    private function forecast(?\App\Entity\User $user = null): array
    {
        $builder = new ForecastBuilder(
            $this->manager,
            $this->manager->getRepository(Transaction::class),
            $this->manager->getRepository(RecurringTransaction::class),
            new MockClock('2026-11-20 10:00:00'),
            'EUR',
        );

        return $builder->build($user ?? $this->user);
    }

    private function account(string $initial = '1000.00', string $currency = 'EUR')
    {
        $account = $this->createAccount('Compte '.$currency, $currency);
        $account->setInitialBalance($initial);
        $this->manager->flush();

        return $account;
    }

    private function rule(string $label, string $amount, string $start, TransactionType $type = TransactionType::Expense, string $currency = 'EUR', bool $active = true, ?string $lastGenerated = null): RecurringTransaction
    {
        $rule = (new RecurringTransaction())
            ->setLabel($label)->setAmount($amount)->setType($type)
            ->setAccount($this->account('0.00', $currency))->setCategory($this->createCategory($label.' cat'))
            ->setFrequency(RecurrenceFrequency::Monthly)->setStartDate(new \DateTimeImmutable($start))->setActive($active);
        if (null !== $lastGenerated) {
            $rule->setLastGeneratedDate(new \DateTimeImmutable($lastGenerated));
        }
        $this->manager->persist($rule);
        $this->manager->flush();

        return $rule;
    }

    public function testTheForecastStartsFromTodaysBalanceAndAddsWhatIsAlreadyScheduled(): void
    {
        $account = $this->account('1000.00');
        $category = $this->createCategory('Divers');
        $this->createDatedTransaction($account, $category, TransactionType::Income, '500.00', '2026-11-03', 'Salaire');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '200.00', '2026-11-10', 'Courses');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '100.00', '2026-11-25', 'Facture à venir');

        $forecast = $this->forecast();

        self::assertSame(1300.0, $forecast['balanceNow']);
        self::assertSame(0.0, $forecast['upcomingIncome']);
        self::assertSame(100.0, $forecast['upcomingExpense']);
        self::assertSame(1200.0, $forecast['projected']);
        self::assertSame('2026-11-30', $forecast['endOfMonth']->format('Y-m-d'));
        self::assertFalse($forecast['negative']);
        self::assertCount(1, $forecast['events']);
        self::assertFalse($forecast['events'][0]['planned']);
    }

    public function testTransactionsAfterTheEndOfTheMonthAreIgnored(): void
    {
        $account = $this->account('1000.00');
        $category = $this->createCategory('Divers');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '300.00', '2026-12-02', 'Décembre');

        $forecast = $this->forecast();

        self::assertSame(1000.0, $forecast['balanceNow']);
        self::assertSame(1000.0, $forecast['projected']);
        self::assertSame([], $forecast['events']);
    }

    public function testRecurringDueDatesNotYetGeneratedAreCounted(): void
    {
        $this->account('1000.00');
        $this->rule('Loyer', '600.00', '2026-11-28');
        $this->rule('Salaire', '1500.00', '2026-11-25', TransactionType::Income);

        $forecast = $this->forecast();

        self::assertSame(1500.0, $forecast['upcomingIncome']);
        self::assertSame(600.0, $forecast['upcomingExpense']);
        self::assertSame(1900.0, $forecast['projected']);
        self::assertSame(['Salaire', 'Loyer'], array_column($forecast['events'], 'label'));
        self::assertTrue($forecast['events'][0]['planned']);
    }

    public function testAnOverdueDueDateCountsFromToday(): void
    {
        $this->account('1000.00');
        $this->rule('Abonnement', '40.00', '2026-11-15');

        $forecast = $this->forecast();

        self::assertSame(960.0, $forecast['projected']);
        self::assertSame('2026-11-20', $forecast['events'][0]['date']->format('Y-m-d'));
    }

    public function testRulesAlreadyGeneratedPausedOrInAnotherCurrencyAreIgnored(): void
    {
        $this->account('1000.00');
        $this->rule('Déjà générée', '50.00', '2026-10-28', lastGenerated: '2026-11-28');
        $this->rule('En pause', '70.00', '2026-11-28', active: false);
        $this->rule('En dollars', '90.00', '2026-11-28', currency: 'USD');

        $forecast = $this->forecast();

        self::assertSame([], $forecast['events']);
        self::assertSame(1000.0, $forecast['projected']);
    }

    public function testTheLowestPointWarnsBeforeTheBalanceTurnsNegative(): void
    {
        $account = $this->account('100.00');
        $category = $this->createCategory('Divers');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '300.00', '2026-11-22', 'Réparation');
        $this->createDatedTransaction($account, $category, TransactionType::Income, '500.00', '2026-11-25', 'Remboursement');

        $forecast = $this->forecast();

        self::assertTrue($forecast['negative']);
        self::assertSame(-200.0, $forecast['lowest']);
        self::assertSame('2026-11-22', $forecast['lowestDate']->format('Y-m-d'));
        self::assertSame(300.0, $forecast['projected'], 'Le solde de fin de mois est positif malgré le creux');
    }

    public function testOnlyTheUsersOwnAccountsAndRulesCount(): void
    {
        $this->account('1000.00');
        $other = $this->createUser('bob@example.test', 'Bob');
        $theirs = $this->createAccount('Compte de Bob', 'EUR', $other);
        $theirs->setInitialBalance('99999.00');
        $this->manager->flush();

        self::assertSame(1000.0, $this->forecast()['balanceNow']);
    }

    public function testTheHomeShowsTheForecastForTheCurrentMonthOnly(): void
    {
        $this->account('1000.00');

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.forecast');
        self::assertSelectorTextContains('.forecast', 'Prévision de fin de mois');
        self::assertSelectorTextContains('.forecast', 'Solde prévu');

        $this->client->request('GET', '/?month=2020-01');
        self::assertSelectorNotExists('.forecast');
    }

    public function testTheForecastBalancesFollowTheMaskingOfTheTotalBalance(): void
    {
        $this->account('1000.00');

        $crawler = $this->client->request('GET', '/');

        // Solde prévu et solde d'aujourd'hui : un montant réel et son masque chacun.
        self::assertCount(2, $crawler->filter('.forecast .amt-real'));
        self::assertCount(2, $crawler->filter('.forecast .amt-mask'));
        // Revenus et dépenses à venir restent visibles.
        self::assertSelectorExists('.forecast .amount-in');
        self::assertSelectorExists('.forecast .amount-out');
    }

    public function testTheHomeWarnsWhenTheBalanceCouldTurnNegative(): void
    {
        $account = $this->account('50.00');
        $category = $this->createCategory('Divers');
        $today = new \DateTimeImmutable('today');
        // Une dépense future dans le mois en cours (la fin du mois est toujours après demain sauf le dernier jour).
        $future = $today->modify('last day of this month');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '400.00', $future->format('Y-m-d'), 'Grosse dépense');

        if ($today->format('Y-m-d') === $future->format('Y-m-d')) {
            self::markTestSkipped('Dernier jour du mois : aucune opération à venir possible.');
        }

        $this->client->request('GET', '/');

        self::assertSelectorExists('.forecast-note.is-warning');
        self::assertSelectorTextContains('.forecast-note', 'Attention');
    }
}
