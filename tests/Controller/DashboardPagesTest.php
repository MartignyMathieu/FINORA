<?php

namespace App\Tests\Controller;

use App\Enum\TransactionType;

/** Accueil, statistiques, paramètres, filtres de transactions et progression des budgets. */
final class DashboardPagesTest extends AbstractCrudTestCase
{
    private function seedNovember(): void
    {
        $account = $this->createAccount('Compte courant');
        $salary = $this->createCategory('Revenus');
        $housing = $this->createCategory('Logement', '#1d6cf0');
        $food = $this->createCategory('Alimentation');

        $this->createDatedTransaction($account, $salary, TransactionType::Income, '2500.00', '2026-11-03', 'Salaire');
        $this->createDatedTransaction($account, $housing, TransactionType::Expense, '500.00', '2026-11-05', 'Loyer');
        $this->createDatedTransaction($account, $food, TransactionType::Expense, '85.40', '2026-11-18', 'Supermarché');
    }

    public function testHomeShowsTotalsForTheRequestedMonth(): void
    {
        $this->seedNovember();

        $this->client->request('GET', '/?month=2026-11');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kpis', "2\u{202F}500,00");   // revenus
        self::assertSelectorTextContains('.kpis', '585,40');     // dépenses
        self::assertSelectorTextContains('.kpis', "1\u{202F}914,60");   // épargne = solde (compte à 0 au départ)
        self::assertSelectorTextContains('.month-nav', 'Novembre 2026');
        self::assertSelectorTextContains('.donut-legend', 'Logement');
        self::assertSelectorExists('.chart svg polyline');
    }

    public function testHomeGreetsTheConfiguredName(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour Mathieu');
    }

    public function testHomeFallsBackToCurrentMonthOnInvalidParameter(): void
    {
        $this->client->request('GET', '/?month=nimporte-quoi');

        self::assertResponseIsSuccessful();
    }

    public function testHomeWithNoDataRenders(): void
    {
        $this->client->request('GET', '/?month=2020-01');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.grid-2', 'Aucune dépense');
    }

    public function testStatsSplitsExpensesByCategoryWithPercentages(): void
    {
        $this->seedNovember();

        $this->client->request('GET', '/stats?month=2026-11');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.donut-legend', 'Logement');
        self::assertSelectorTextContains('.donut-legend', '85');       // 500 / 585,40
        self::assertSelectorTextContains('.donut-legend', '500,00');
        self::assertSelectorTextContains('.donut-center', '585,40');
    }

    public function testStatsCanShowIncomeInstead(): void
    {
        $this->seedNovember();

        $this->client->request('GET', '/stats?month=2026-11&type=income');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.donut-legend', 'Revenus');
        self::assertSelectorTextContains('.donut-center', "2\u{202F}500,00");
    }

    public function testSettingsLinksToCategories(): void
    {
        $crawler = $this->client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/category"]'));
    }

    public function testAccountsShowBalanceFromInitialBalanceAndTransactions(): void
    {
        $account = $this->createAccount('Compte courant');
        $account->setInitialBalance('1000.00');
        $this->manager->flush();
        $category = $this->createCategory();
        $this->createDatedTransaction($account, $category, TransactionType::Income, '300.00');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '120.50');

        $this->client->request('GET', '/account');

        self::assertSelectorTextContains('.list', "1\u{202F}179,50");
    }

    public function testTransactionsAreFilteredByType(): void
    {
        $this->seedNovember();

        $this->client->request('GET', '/transaction?type=income');
        self::assertSelectorTextContains('.tx-list', 'Salaire');
        self::assertSelectorTextNotContains('.tx-list', 'Loyer');

        $this->client->request('GET', '/transaction?type=expense');
        self::assertSelectorTextContains('.tx-list', 'Loyer');
        self::assertSelectorTextNotContains('.tx-list', 'Salaire');
    }

    public function testTransactionsAreSearchedByLabelOrCategory(): void
    {
        $this->seedNovember();

        $this->client->request('GET', '/transaction?q=super');
        self::assertSelectorTextContains('.tx-list', 'Supermarché');
        self::assertSelectorTextNotContains('.tx-list', 'Loyer');

        $this->client->request('GET', '/transaction?q=logement');
        self::assertSelectorTextContains('.tx-list', 'Loyer');
    }

    public function testTransactionSearchTreatsWildcardsLiterally(): void
    {
        $this->seedNovember();

        $this->client->request('GET', '/transaction?q=%25');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.tx-list');
    }

    public function testTransactionsAreGroupedByMonthMostRecentFirst(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '10.00', '2026-10-02', 'Octobre');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '20.00', '2026-11-02', 'Novembre');

        $crawler = $this->client->request('GET', '/transaction');

        $headings = $crawler->filter('.month-heading')->each(static fn ($n) => $n->text());
        self::assertSame(['Novembre 2026', 'Octobre 2026'], $headings);
    }

    public function testBudgetsShowSpentAmountAndProgress(): void
    {
        $account = $this->createAccount();
        $food = $this->createCategory('Alimentation');
        $this->createBudget($food, '2026-11-01', '500.00');
        $this->createDatedTransaction($account, $food, TransactionType::Expense, '320.00', '2026-11-10');
        // Hors du mois : ne doit pas compter.
        $this->createDatedTransaction($account, $food, TransactionType::Expense, '999.00', '2026-10-10');

        $this->client->request('GET', '/budget?month=2026-11');

        self::assertSelectorTextContains('.budget-card', '320,00');
        self::assertSelectorTextContains('.budget-card', '500,00');
        self::assertSelectorTextContains('.budget-card .pct', '64');
        self::assertSelectorExists('.budget-card .bar.ok');
    }

    public function testBudgetOverspendIsFlagged(): void
    {
        $account = $this->createAccount();
        $leisure = $this->createCategory('Loisirs');
        $this->createBudget($leisure, '2026-11-01', '100.00');
        $this->createDatedTransaction($account, $leisure, TransactionType::Expense, '130.00', '2026-11-10');

        $this->client->request('GET', '/budget?month=2026-11');

        self::assertSelectorExists('.budget-card .bar.over');
        self::assertSelectorTextContains('.budget-card .pct', '130');
    }

    public function testBudgetsOnlyListTheRequestedMonth(): void
    {
        $this->createBudget($this->createCategory('Loisirs'), '2026-11-01');

        $this->client->request('GET', '/budget?month=2026-12');

        self::assertSelectorTextContains('.list', 'Aucun budget');
    }
}
