<?php

namespace App\Tests\Controller;

use App\Enum\TransactionType;

/** Filtres de la liste des transactions : période, montants, compte, catégorie, totaux, pastilles et export. */
final class TransactionFilterTest extends AbstractCrudTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    /**
     * Jeu de référence (Alice) : 6 transactions sur deux comptes et trois catégories.
     * Courant : Loyer −500 (5 nov.), Courses −85,40 (18 nov.), Salaire +2 500 (3 nov.), Cinéma −12 (30 oct.)
     * Livret : Intérêts +40 (15 nov.), Retrait −200 (20 nov.).
     */
    private function seed(): void
    {
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');
        $housing = $this->createCategory('Logement');
        $food = $this->createCategory('Alimentation');
        $salary = $this->createCategory('Revenus');
        $leisure = $this->createCategory('Loisirs');
        $this->ids = ['courant' => $courant->getId(), 'livret' => $livret->getId(), 'food' => $food->getId(), 'housing' => $housing->getId()];

        $this->createDatedTransaction($courant, $housing, TransactionType::Expense, '500.00', '2026-11-05', 'Loyer');
        $this->createDatedTransaction($courant, $food, TransactionType::Expense, '85.40', '2026-11-18', 'Courses');
        $this->createDatedTransaction($courant, $salary, TransactionType::Income, '2500.00', '2026-11-03', 'Salaire');
        $this->createDatedTransaction($courant, $leisure, TransactionType::Expense, '12.00', '2026-10-30', 'Cinéma');
        $this->createDatedTransaction($livret, $salary, TransactionType::Income, '40.00', '2026-11-15', 'Intérêts');
        $this->createDatedTransaction($livret, $leisure, TransactionType::Expense, '200.00', '2026-11-20', 'Retrait');
    }

    /** @return list<string> Libellés affichés, dans l'ordre de la page. */
    private function labels(string $query = ''): array
    {
        $crawler = $this->client->request('GET', '/transaction'.$query);
        self::assertResponseIsSuccessful();

        return $crawler->filter('.tx-list .tx .name')->each(static fn ($n): string => $n->text());
    }

    public function testWithoutFilterEverythingIsListedNewestFirst(): void
    {
        $this->seed();

        self::assertSame(['Retrait', 'Courses', 'Intérêts', 'Loyer', 'Salaire', 'Cinéma'], $this->labels());
    }

    public function testThePeriodIncludesItsBounds(): void
    {
        $this->seed();

        self::assertSame(['Courses', 'Intérêts', 'Loyer', 'Salaire'], $this->labels('?from=2026-11-03&to=2026-11-18'));
        self::assertSame(['Cinéma'], $this->labels('?to=2026-10-31'));
        self::assertSame(['Retrait'], $this->labels('?from=2026-11-19'));
    }

    public function testAmountRangeUsesTheAbsoluteValueForIncomeAndExpenses(): void
    {
        $this->seed();

        self::assertSame(['Retrait', 'Loyer', 'Salaire'], $this->labels('?min=200'));
        self::assertSame(['Cinéma'], $this->labels('?max=12'));
        self::assertSame(['Courses', 'Intérêts'], $this->labels('?min=40&max=85.40'));
        self::assertSame(['Courses', 'Intérêts'], $this->labels('?min=40&max=85,4'), 'La virgule est acceptée');
    }

    public function testTheAccountAndTheCategoryNarrowTheList(): void
    {
        $this->seed();

        self::assertSame(['Retrait', 'Intérêts'], $this->labels('?account='.$this->ids['livret']));
        self::assertSame(['Courses'], $this->labels('?category='.$this->ids['food']));
        self::assertSame(['Loyer'], $this->labels('?account='.$this->ids['courant'].'&category='.$this->ids['housing']));
        self::assertSame([], $this->labels('?account='.$this->ids['livret'].'&category='.$this->ids['housing']));
    }

    public function testCriteriaCombineWithTheTabsAndTheSearch(): void
    {
        $this->seed();

        self::assertSame(['Retrait', 'Courses', 'Loyer', 'Cinéma'], $this->labels('?type=expense'));
        self::assertSame(['Retrait', 'Loyer'], $this->labels('?type=expense&min=100'));
        self::assertSame(['Courses'], $this->labels('?type=expense&min=50&max=100&q=cour'));
        self::assertSame(['Salaire'], $this->labels('?type=income&from=2026-11-01&account='.$this->ids['courant']));
    }

    public function testAnotherUsersAccountOrCategoryNeverLeaks(): void
    {
        $this->seed();
        $other = $this->createUser('bob@example.test', 'Bob');
        $theirAccount = $this->createAccount('Compte de Bob', 'EUR', $other);
        $theirCategory = $this->createCategory('Secret', null, $other);
        $this->createDatedTransaction($theirAccount, $theirCategory, TransactionType::Expense, '77.00', '2026-11-10', 'Achat privé de Bob');

        self::assertSame([], $this->labels('?account='.$theirAccount->getId()));
        self::assertSame([], $this->labels('?category='.$theirCategory->getId()));
        self::assertNotContains('Achat privé de Bob', $this->labels());
    }

    public function testTheSummaryCountsEveryMatchAndSumsTheDefaultCurrency(): void
    {
        $this->seed();
        $usd = $this->createAccount('Voyage', 'USD');
        $this->createDatedTransaction($usd, $this->createCategory('Voyage'), TransactionType::Expense, '300.00', '2026-11-12', 'Hôtel');

        $crawler = $this->client->request('GET', '/transaction?from=2026-11-01&to=2026-11-30');

        $summary = $crawler->filter('.filter-summary')->text();
        self::assertStringContainsString('6 transactions', $summary, 'Le dollar compte dans le nombre');
        self::assertStringContainsString("+2\u{202F}540,00", $summary, 'Revenus : 2 500 + 40');
        self::assertStringContainsString("\u{2212}785,40", $summary, 'Dépenses en euros : 500 + 85,40 + 200 (pas les 300 $)');
    }

    public function testNoSummaryWhenNothingMatches(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/transaction?min=99999');

        self::assertSelectorNotExists('.filter-summary');
        self::assertSelectorTextContains('.empty', 'ne correspond à ces filtres');
        self::assertSelectorExists('.empty a[href="/transaction"]');
        self::assertCount(0, $crawler->filter('.tx-list'));
    }

    public function testActiveFiltersAreShownAsChipsThatRemoveThemselves(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/transaction?from=2026-11-01&to=2026-11-30&min=50&account='.$this->ids['courant'].'&type=expense');

        $chips = $crawler->filter('.filter-chips li')->each(static fn ($li): string => $li->text());
        self::assertCount(3, $chips);
        self::assertStringContainsString('Du 01/11/2026 au 30/11/2026', $chips[0]);
        self::assertStringContainsString('Montant d\'au moins 50,00', $chips[1]);
        self::assertStringContainsString('Compte : Compte courant', $chips[2]);

        // Retirer la période : les autres critères et l'onglet restent dans le lien.
        $href = $crawler->filter('.filter-chips li a')->first()->attr('href');
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        self::assertSame(['type' => 'expense', 'min' => '50.00', 'account' => (string) $this->ids['courant']], $query);
        self::assertSelectorTextContains('.filters-count', '3');
    }

    public function testThePanelIsOpenOnlyWhenAnAdvancedFilterIsActive(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/transaction');
        self::assertCount(0, $crawler->filter('details.filters[open]'));
        self::assertSelectorNotExists('.filters-count');

        $crawler = $this->client->request('GET', '/transaction?type=expense&q=loyer');
        self::assertCount(0, $crawler->filter('details.filters[open]'), 'Le type et la recherche ont leur propre barre');

        $crawler = $this->client->request('GET', '/transaction?max=50');
        self::assertCount(1, $crawler->filter('details.filters[open]'));
    }

    public function testTheQuickPeriodsAreOffered(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/transaction');

        $labels = $crawler->filter('.filter-presets a')->each(static fn ($a): string => $a->text());
        self::assertSame(['Ce mois-ci', 'Mois dernier', '30 derniers jours', 'Cette année'], $labels);
        $href = $crawler->filter('.filter-presets a')->first()->attr('href');
        self::assertStringContainsString('from='.date('Y-m-01'), $href);
        self::assertStringContainsString('to='.date('Y-m-t'), $href);
    }

    public function testTabsAndSearchKeepTheAdvancedFilters(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/transaction?from=2026-11-01&min=50&account='.$this->ids['courant']);

        $incomeHref = $crawler->filter('.segmented a')->eq(1)->attr('href');
        parse_str((string) parse_url($incomeHref, \PHP_URL_QUERY), $query);
        self::assertSame('income', $query['type']);
        self::assertSame('2026-11-01', $query['from']);
        self::assertSame('50.00', $query['min']);

        $hidden = $crawler->filter('form.search input[type="hidden"]')->each(static fn ($i): string => $i->attr('name').'='.$i->attr('value'));
        self::assertContains('from=2026-11-01', $hidden);
        self::assertContains('min=50.00', $hidden);
        self::assertContains('account='.$this->ids['courant'], $hidden);
    }

    public function testTheFormOffersOnlyTheUsersAccountsAndCategories(): void
    {
        $this->seed();
        $other = $this->createUser('bob@example.test', 'Bob');
        $this->createAccount('Compte de Bob', 'EUR', $other);
        $this->createCategory('Catégorie de Bob', null, $other);

        $crawler = $this->client->request('GET', '/transaction');

        $accounts = $crawler->filter('select[name="account"] option')->each(static fn ($o): string => $o->text());
        self::assertContains('Compte courant (EUR)', $accounts);
        self::assertNotContains('Compte de Bob (EUR)', $accounts);
        $categories = $crawler->filter('select[name="category"] option')->each(static fn ($o): string => $o->text());
        self::assertContains('Logement', $categories);
        self::assertNotContains('Catégorie de Bob', $categories);
    }

    public function testSubmittingTheFormFiltersTheList(): void
    {
        $this->seed();

        $this->client->request('GET', '/transaction');
        $crawler = $this->client->submitForm('Appliquer', [
            'from' => '2026-11-01',
            'to' => '2026-11-30',
            'min' => '100',
            'account' => (string) $this->ids['courant'],
        ], 'GET');

        self::assertResponseIsSuccessful();
        self::assertSame(['Loyer', 'Salaire'], $crawler->filter('.tx-list .tx .name')->each(static fn ($n): string => $n->text()));
    }

    public function testInvalidCriteriaAreRejected(): void
    {
        $this->seed();

        foreach (['?from=31/12/2026', '?min=abc', '?max=1e5', '?account=abc', '?category=-1', '?type=virement', '?from=2026-11-30&to=2026-11-01', '?min=100&max=10'] as $query) {
            $this->client->request('GET', '/transaction'.$query);
            self::assertResponseStatusCodeSame(422, $query);
        }
    }

    public function testTheExportKeepsTheSameFilters(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/transaction?min=100&account='.$this->ids['courant']);
        $href = $crawler->filter('a[href^="/export/transactions"]')->attr('href');
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        self::assertSame(['min' => '100.00', 'account' => (string) $this->ids['courant']], $query);

        $this->client->request('GET', $href);
        $body = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('Loyer', $body);
        self::assertStringContainsString('Salaire', $body);
        self::assertStringNotContainsString('Courses', $body);
        self::assertStringNotContainsString('Retrait', $body, 'Compte livret exclu');
    }

    public function testTheExportAcceptsTheNewCriteriaAndRejectsInvalidOnes(): void
    {
        $this->seed();

        $this->client->request('GET', '/export/transactions?category='.$this->ids['food']);
        $body = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('Courses', $body);
        self::assertStringNotContainsString('Loyer', $body);

        $this->client->request('GET', '/export/transactions?min=10&max=5');
        self::assertResponseStatusCodeSame(422);
    }
}
