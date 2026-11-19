<?php

namespace App\Tests\Controller;

use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;

/** Les mois utilisés sont lointains (2030) pour ne pas dépendre de la date du jour, sauf quand c'est le but. */
final class CalendarControllerTest extends AbstractCrudTestCase
{
    public function testGridCoversWholeWeeksFromMonday(): void
    {
        // Novembre 2026 : le 1er est un dimanche, le 30 un lundi → 6 semaines.
        $crawler = $this->client->request('GET', '/calendar?month=2026-11');

        self::assertResponseIsSuccessful();
        self::assertCount(42, $crawler->filter('.cal-body .cal-day'));
        self::assertCount(30, $crawler->filter('a.cal-day'));
        self::assertSame('26', trim($crawler->filter('.cal-body .cal-day')->first()->text()), 'La grille commence le lundi 26 octobre.');
        self::assertCount(1, $crawler->filter('.cal-body .cal-day.is-outside:first-child'));
        self::assertSame(['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'], $crawler->filter('.cal-head span')->each(static fn ($n) => $n->text()));
        self::assertSelectorTextContains('.month-nav', 'Novembre 2026');
    }

    public function testAMonthThatFillsExactlyFourWeeks(): void
    {
        // Février 2027 : du lundi 1er au dimanche 28.
        $crawler = $this->client->request('GET', '/calendar?month=2027-02');

        self::assertCount(28, $crawler->filter('.cal-body .cal-day'));
        self::assertCount(0, $crawler->filter('.cal-day.is-outside'));
    }

    public function testDayChipsShowTheDaysTotals(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '30.00', '2030-06-12');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '20.00', '2030-06-12');
        $this->createDatedTransaction($account, $category, TransactionType::Income, '1250.00', '2030-06-12');

        $crawler = $this->client->request('GET', '/calendar?month=2030-06');

        $day = $crawler->filter('a.cal-day')->reduce(static fn ($n) => str_starts_with(trim($n->filter('.cal-num')->text()), '12'));
        self::assertCount(1, $day);
        self::assertSame("\u{2212}50\u{00A0}€", $day->filter('.cal-out')->text());
        self::assertSame("+1\u{202F}250\u{00A0}€", $day->filter('.cal-in')->text());
        self::assertStringContainsString('3 opération(s)', $day->attr('aria-label'));
    }

    public function testMonthTotals(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createDatedTransaction($account, $category, TransactionType::Income, '2500.00', '2030-06-03');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '600.00', '2030-06-05');
        // Hors du mois : ignorées.
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '999.00', '2030-05-31');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '888.00', '2030-07-01');

        $this->client->request('GET', '/calendar?month=2030-06');

        self::assertSelectorTextContains('.cal-kpis', "2\u{202F}500,00");
        self::assertSelectorTextContains('.cal-kpis', '600,00');
        self::assertSelectorTextContains('.cal-kpis', "+1\u{202F}900,00");
        self::assertSelectorTextNotContains('.cal-kpis', '999');
        self::assertSelectorTextNotContains('.cal-kpis', '888');
    }

    public function testOtherCurrenciesAreListedButNotSummed(): void
    {
        $category = $this->createCategory();
        $dollars = $this->createAccount('Compte USD', 'USD');
        $this->createDatedTransaction($dollars, $category, TransactionType::Expense, '999.00', '2030-06-12', 'Achat en dollars');

        $crawler = $this->client->request('GET', '/calendar?month=2030-06&day=2030-06-12');

        self::assertSelectorTextNotContains('.cal-kpis', '999');
        self::assertCount(0, $crawler->filter('.cal-chip'));
        self::assertSelectorTextContains('#detail', 'Achat en dollars');
        self::assertSelectorTextContains('#detail', '999,00');
        self::assertSelectorTextContains('#detail', '$');
    }

    public function testUpcomingRecurringOccurrencesAreShownAsPlanned(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createRecurring($account, $category, '2030-01-10', RecurrenceFrequency::Monthly, '500.00', TransactionType::Expense, 'Loyer');
        $this->createRecurring($account, $category, '2030-01-10', RecurrenceFrequency::Monthly, '2500.00', TransactionType::Income, 'Salaire');
        // Ni en pause ni terminée : jamais affichées.
        $this->createRecurring($account, $category, '2030-01-10', RecurrenceFrequency::Monthly, '111.00', TransactionType::Expense, 'En pause', active: false);
        $this->createRecurring($account, $category, '2030-01-10', RecurrenceFrequency::Monthly, '222.00', TransactionType::Expense, 'Terminée', end: '2030-03-31');

        $crawler = $this->client->request('GET', '/calendar?month=2030-06&day=2030-06-10');

        self::assertCount(1, $crawler->filter('.cal-plan'), 'Une seule case porte des échéances : le 10 juin.');
        self::assertSelectorTextContains('.cal-plan', '2');
        self::assertSelectorTextContains('#detail', 'Loyer');
        self::assertSelectorTextContains('#detail', 'Salaire');
        self::assertSelectorTextContains('#detail', 'Prévue');
        self::assertSelectorTextNotContains('#detail', 'En pause');
        self::assertSelectorTextNotContains('#detail', 'Terminée');
        // 2 500 − 500 de récurrences à venir.
        self::assertSelectorTextContains('.cal-kpis', "+2\u{202F}000,00");
    }

    public function testAnOccurrenceAlreadyGeneratedIsNotShownAsPlanned(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createRecurring($account, $category, '2030-01-10', RecurrenceFrequency::Monthly, '500.00', TransactionType::Expense, 'Loyer', lastGenerated: '2030-06-10');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '500.00', '2030-06-10', 'Loyer');

        $crawler = $this->client->request('GET', '/calendar?month=2030-06&day=2030-06-10');

        self::assertCount(0, $crawler->filter('.cal-plan'));
        self::assertSelectorTextContains('#detail', 'Loyer');
        self::assertSelectorTextNotContains('#detail', 'Prévue');
        self::assertSelectorTextContains('.cal-kpis', '500,00');
    }

    public function testSelectedDayShowsItsTransactions(): void
    {
        $account = $this->createAccount('Compte courant');
        $this->createDatedTransaction($account, $this->createCategory('Alimentation'), TransactionType::Expense, '42.50', '2030-06-12', 'Supermarché');
        $this->createDatedTransaction($account, $this->createCategory('Loisirs'), TransactionType::Expense, '15.00', '2030-06-13', 'Cinéma');

        $this->client->request('GET', '/calendar?month=2030-06&day=2030-06-12');

        self::assertSelectorTextContains('#detail h2', '12 juin 2030');
        self::assertSelectorTextContains('#detail', 'Supermarché');
        self::assertSelectorTextContains('#detail', 'Alimentation');
        self::assertSelectorTextContains('#detail', 'Compte courant');
        self::assertSelectorTextNotContains('#detail', 'Cinéma');
        self::assertSelectorExists('a.cal-day.is-selected');
    }

    public function testADayWithoutOperations(): void
    {
        $this->client->request('GET', '/calendar?month=2030-06&day=2030-06-20');

        self::assertSelectorTextContains('#detail', 'Aucune opération ce jour-là');
    }

    public function testNoDayIsSelectedByDefaultOutsideTheCurrentMonth(): void
    {
        $this->client->request('GET', '/calendar?month=2030-06');

        self::assertSelectorTextContains('#detail', 'Sélectionnez un jour');
        self::assertSelectorNotExists('.cal-day.is-selected');
    }

    public function testInvalidOrOutOfMonthDaysAreIgnored(): void
    {
        foreach (['pas-une-date', '2030-06-31', '2030-07-01', '2030-05-31'] as $day) {
            $this->client->request('GET', '/calendar?month=2030-06&day='.$day);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#detail', 'Sélectionnez un jour');
        }
    }

    public function testTheCurrentMonthSelectsTodayByDefault(): void
    {
        $this->client->request('GET', '/calendar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.cal-day.is-today.is-selected');
        self::assertSelectorTextContains('#detail', 'Ajouter une transaction');
    }

    public function testTheAddLinkPrefillsTheSelectedDate(): void
    {
        $crawler = $this->client->request('GET', '/calendar?month=2030-06&day=2030-06-12');

        $link = $crawler->selectLink('Ajouter une transaction')->link();
        self::assertStringEndsWith('/transaction/new?date=2030-06-12', $link->getUri());

        $this->client->click($link);
        self::assertInputValueSame('transaction[date]', '2030-06-12');
    }

    public function testMonthNavigationLinks(): void
    {
        $crawler = $this->client->request('GET', '/calendar?month=2030-01');

        $links = $crawler->filter('.month-nav a')->each(static fn ($n) => $n->attr('href'));
        self::assertStringContainsString('month=2029-12', $links[0]);
        self::assertStringContainsString('month=2030-02', $links[1]);
    }

    public function testAnInvalidMonthFallsBackToTheCurrentOne(): void
    {
        $this->client->request('GET', '/calendar?month=nimporte-quoi');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.cal-day.is-today');
    }

    public function testOtherUsersDataIsNeverShown(): void
    {
        $bob = $this->createUser('bob@example.test', 'Bob');
        $alicesAccount = $this->createAccount('Compte d\'Alice');
        $alicesCategory = $this->createCategory('Catégorie d\'Alice');
        $this->createDatedTransaction($alicesAccount, $alicesCategory, TransactionType::Expense, '77.00', '2030-06-12', 'Achat d\'Alice');
        $this->createRecurring($alicesAccount, $alicesCategory, '2030-01-12', label: 'Loyer d\'Alice');
        $this->client->loginUser($bob);

        $crawler = $this->client->request('GET', '/calendar?month=2030-06&day=2030-06-12');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('d\'Alice', $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('.cal-chip'));
        self::assertSelectorTextContains('.cal-kpis', '0,00');
    }

    public function testTheNavigationLinksToTheCalendar(): void
    {
        $crawler = $this->client->request('GET', '/calendar');

        self::assertCount(1, $crawler->filter('.sidebar a.active[href="/calendar"]'));
        self::assertCount(0, $crawler->filter('.nav .disabled'));
    }
}
