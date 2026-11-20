<?php

namespace App\Tests\Controller;

use App\Enum\TransactionType;
use App\Service\BudgetAlerts;

/** Alertes de budget : seuils (80 %, atteint, dépassé), bannière, pastille du menu, isolation. */
final class BudgetAlertsTest extends AbstractCrudTestCase
{
    /** Dépense du mois en cours pour une catégorie, avec un budget du mois. */
    private function budgetWith(string $name, string $limit, string $spent, ?string $currency = null)
    {
        $account = $this->createAccount('Compte '.$name, $currency ?? 'EUR');
        $category = $this->createCategory($name);
        $budget = $this->createBudget($category, (new \DateTimeImmutable('first day of this month'))->format('Y-m-d'), $limit);
        if (null !== $currency) {
            $budget->setCurrency($currency);
            $this->manager->flush();
        }
        if ('0' !== $spent) {
            $this->createDatedTransaction($account, $category, TransactionType::Expense, $spent, (new \DateTimeImmutable('today'))->format('Y-m-d'), 'Dépense '.$name);
        }

        return $budget;
    }

    private function alerts(): array
    {
        // FinanceStats lit l'utilisateur connecté : on initialise la sécurité par une requête.
        $this->client->request('GET', '/budget');

        return static::getContainer()->get(BudgetAlerts::class)->current();
    }

    public function testNoAlertBelowEightyPercent(): void
    {
        $this->budgetWith('Courses', '100.00', '79.00');

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorNotExists('.budget-alerts');
        self::assertSelectorNotExists('.alert-badge');
        self::assertCount(0, $crawler->filter('.budget-alerts'));
    }

    public function testWarningFromEightyPercent(): void
    {
        $this->budgetWith('Courses', '100.00', '80.00');

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('.budget-alerts.is-warn');
        self::assertSelectorTextContains('.budget-alerts', 'Courses');
        self::assertSelectorTextContains('.budget-alerts', '80');
        self::assertSelectorTextContains('.budget-alerts', '1 budget à surveiller');
        self::assertCount(1, $crawler->filter('.budget-alerts li.is-warn'));
    }

    public function testReachedAndExceededBudgetsAreFlaggedAndComeFirst(): void
    {
        $this->budgetWith('Courses', '100.00', '85.00');
        $this->budgetWith('Restaurants', '60.00', '60.00');
        $this->budgetWith('Loisirs', '150.00', '180.00');

        $crawler = $this->client->request('GET', '/');

        $levels = $crawler->filter('.budget-alerts li')->each(static fn ($li): string => str_contains((string) $li->attr('class'), 'is-over') ? 'over' : (str_contains((string) $li->attr('class'), 'is-full') ? 'full' : 'warn'));
        self::assertSame(['over', 'full', 'warn'], $levels);
        self::assertSelectorExists('.budget-alerts.is-over');
        self::assertSelectorTextContains('.budget-alerts li.is-over', 'dépassé');
        self::assertSelectorTextContains('.budget-alerts li.is-full', 'atteint');
        self::assertSelectorTextContains('.budget-alerts', '3 budgets à surveiller');
    }

    public function testTheServiceOrdersAlertsAndBuildsASignature(): void
    {
        $courses = $this->budgetWith('Courses', '100.00', '85.00');
        $loisirs = $this->budgetWith('Loisirs', '150.00', '180.00');

        $alerts = $this->alerts();

        self::assertSame(['over', 'warn'], array_column($alerts, 'level'));
        self::assertSame($loisirs->getId().':over,'.$courses->getId().':warn', BudgetAlerts::signature($alerts));
    }

    public function testTheSignatureChangesWhenABudgetChangesLevel(): void
    {
        $budget = $this->budgetWith('Courses', '100.00', '85.00');
        $before = BudgetAlerts::signature($this->alerts());

        $account = $this->manager->getRepository(\App\Entity\Account::class)->findOneBy([]);
        $this->createDatedTransaction($account, $budget->getCategory(), TransactionType::Expense, '30.00', (new \DateTimeImmutable('today'))->format('Y-m-d'), 'Encore');

        self::assertNotSame($before, BudgetAlerts::signature($this->alerts()));
    }

    public function testOnlyTheCurrentMonthCounts(): void
    {
        $account = $this->createAccount('Compte');
        $category = $this->createCategory('Courses');
        $lastMonth = new \DateTimeImmutable('first day of last month');
        $this->createBudget($category, $lastMonth->format('Y-m-d'), '100.00');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '500.00', $lastMonth->format('Y-m-d'), 'Mois dernier');

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('.budget-alerts');
    }

    public function testAnotherUsersBudgetsNeverRaiseAnAlert(): void
    {
        $other = $this->createUser('bob@example.test', 'Bob');
        $account = $this->createAccount('Compte de Bob', 'EUR', $other);
        $category = $this->createCategory('Courses', null, $other);
        $this->createBudget($category, (new \DateTimeImmutable('first day of this month'))->format('Y-m-d'), '10.00');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '500.00', (new \DateTimeImmutable('today'))->format('Y-m-d'), 'Dépense de Bob');

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('.budget-alerts');
        self::assertSelectorNotExists('.alert-badge');
    }

    public function testTheMenuBadgeCountsTheBudgetsToWatchOnEveryPage(): void
    {
        $this->budgetWith('Courses', '100.00', '90.00');
        $this->budgetWith('Loisirs', '150.00', '180.00');

        foreach (['/', '/account', '/goal', '/stats'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame('2', trim($crawler->filter('.sidebar .alert-badge')->text()), $url);
            self::assertSame('2', trim($crawler->filter('.tabbar .alert-badge')->text()), $url);
        }
    }

    public function testTheBannerAlsoAppearsOnTheBudgetsPageForTheCurrentMonthOnly(): void
    {
        $this->budgetWith('Courses', '100.00', '95.00');

        $this->client->request('GET', '/budget');
        self::assertSelectorExists('.budget-alerts');

        $this->client->request('GET', '/budget?month=2020-01');
        self::assertSelectorNotExists('.budget-alerts');
    }

    public function testTheBannerIsDismissibleAndTracksItsSignature(): void
    {
        $budget = $this->budgetWith('Courses', '100.00', '95.00');

        $crawler = $this->client->request('GET', '/');

        $banner = $crawler->filter('.budget-alerts');
        self::assertSame('alert-dismiss', $banner->attr('data-controller'));
        self::assertSame($budget->getId().':warn', $banner->attr('data-alert-dismiss-signature-value'));
        self::assertSelectorExists('.budget-alerts button[data-action="alert-dismiss#dismiss"][aria-label]');
        self::assertSelectorExists('.budget-alerts a[href="/budget/'.$budget->getId().'"]');
    }

    public function testNothingIsComputedForVisitorsAndTheLoginPageStaysClean(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.alert-badge');
        self::assertSelectorNotExists('.budget-alerts');
    }

    public function testTheThresholdIsEightyPercent(): void
    {
        self::assertSame(0.8, BudgetAlerts::THRESHOLD);
    }
}
