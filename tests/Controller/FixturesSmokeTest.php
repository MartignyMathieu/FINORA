<?php

namespace App\Tests\Controller;

use App\DataFixtures\DemoFixtures;
use App\DataFixtures\TestUserFixtures;
use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\User;

/**
 * Charge les jeux de données des testeurs (fixtures « test » et « demo ») et vérifie que chaque page
 * s'affiche pour chaque utilisateur, avec les cas limites voulus. Si ce test passe, le plan de vérification
 * manuel (docs/TESTING.md) part d'une base saine.
 */
final class FixturesSmokeTest extends AbstractCrudTestCase
{
    private function load(string $fixtureClass): void
    {
        static::getContainer()->get($fixtureClass)->load($this->manager);
        $this->manager->clear();
    }

    private function loginAs(string $email): User
    {
        $user = $this->manager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, $email.' doit exister');
        $this->client->loginUser($user);

        return $user;
    }

    /** @return list<string> Toutes les pages (listes, détails, formulaires, filtres, mois voisins) d'un utilisateur. */
    private function pagesOf(User $user): array
    {
        $month = new \DateTimeImmutable('first day of this month');
        $prev = $month->modify('-1 month')->format('Y-m');
        $next = $month->modify('+1 month')->format('Y-m');
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $pages = [
            '/', "/?month=$prev", "/?month=$next", '/account', '/account/new', '/transaction', '/transaction?type=income',
            '/transaction?type=expense', '/transaction?q=a', '/transaction/new', '/transaction/new?date='.$today,
            '/recurring', '/recurring/new', '/budget', "/budget?month=$prev", "/budget?month=$next", '/budget/new',
            '/goal', '/goal/new', '/category', '/category/new', '/stats', '/stats?type=income', "/stats?month=$prev",
            '/calendar', "/calendar?day=$today", "/calendar?month=$prev", "/calendar?month=$next", '/settings', '/profile',
            '/export/transactions', '/export/transactions?type=expense', '/export/transactions?format=intl',
        ];

        $owned = ['owner' => $user];
        foreach ($this->manager->getRepository(Account::class)->findBy($owned) as $account) {
            array_push($pages, "/account/{$account->getId()}", "/account/{$account->getId()}/edit");
        }
        foreach ($this->manager->getRepository(Category::class)->findBy($owned) as $category) {
            array_push($pages, "/category/{$category->getId()}", "/category/{$category->getId()}/edit");
        }
        foreach ($this->manager->getRepository(Goal::class)->findBy($owned) as $goal) {
            array_push($pages, "/goal/{$goal->getId()}", "/goal/{$goal->getId()}/edit");
        }
        foreach ($this->manager->getRepository(RecurringTransaction::class)->findByOwner($user) as $rule) {
            $pages[] = "/recurring/{$rule->getId()}/edit";
        }
        foreach ($this->manager->createQuery('SELECT b FROM '.Budget::class.' b JOIN b.category c WHERE c.owner = :u')->setParameter('u', $user)->getResult() as $budget) {
            array_push($pages, "/budget/{$budget->getId()}", "/budget/{$budget->getId()}/edit");
        }
        // Un échantillon de transactions, plus celles qui testent les textes difficiles.
        $transactions = $this->manager->getRepository(Transaction::class)->search($user);
        foreach (array_slice($transactions, 0, 6) as $transaction) {
            array_push($pages, "/transaction/{$transaction->getId()}", "/transaction/{$transaction->getId()}/edit");
        }
        foreach ($transactions as $transaction) {
            if (str_contains((string) $transaction->getLabel(), '<b>') || strlen((string) $transaction->getLabel()) > 100) {
                array_push($pages, "/transaction/{$transaction->getId()}", "/transaction/{$transaction->getId()}/edit");
            }
        }

        return array_values(array_unique($pages));
    }

    /** @return array<string, string> Contenu de chaque page (échoue dès qu'une page n'est pas un succès). */
    private function crawl(User $user): array
    {
        $contents = [];
        foreach ($this->pagesOf($user) as $url) {
            $this->client->request('GET', $url);
            $content = (string) $this->client->getResponse()->getContent();
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $user->getEmail().' → '.$url);
            self::assertStringNotContainsString('Exception', $content, $url);
            self::assertStringNotContainsString('Warning:', $content, $url);
            $contents[$url] = $content;
        }

        return $contents;
    }

    public function testEveryPageRendersForTheFullTestUser(): void
    {
        $this->load(TestUserFixtures::class);
        $user = $this->loginAs(TestUserFixtures::EMAIL);

        $contents = $this->crawl($user);

        self::assertGreaterThan(100, \count($contents));
        foreach ($contents as $url => $content) {
            self::assertStringNotContainsString('(autre utilisateur)', $content, 'Données de l\'autre utilisateur visibles sur '.$url);
        }
    }

    public function testTheFullTestUserCoversTheEdgeCases(): void
    {
        $this->load(TestUserFixtures::class);
        $this->loginAs(TestUserFixtures::EMAIL);
        $month = new \DateTimeImmutable('first day of this month');

        // Budgets : dépassé (Loisirs 180/150), à surveiller (Shopping 85 %, Voyage en dollars), mois sans dépense.
        $crawler = $this->client->request('GET', '/budget');
        self::assertGreaterThanOrEqual(1, $crawler->filter('.budget-card .bar.over')->count(), 'Un budget dépassé');
        self::assertGreaterThanOrEqual(2, $crawler->filter('.budget-card .bar.warn')->count(), 'Des budgets à surveiller');
        self::assertGreaterThanOrEqual(1, $crawler->filter('.budget-card .bar.ok')->count(), 'Un budget sous contrôle');
        self::assertSelectorTextContains('.list', '$');
        $this->client->request('GET', '/budget?month='.$month->modify('+1 month')->format('Y-m'));
        self::assertSelectorTextContains('.budget-card .pct', '0');

        // Comptes : un de chaque type, solde négatif, devise étrangère.
        $this->client->request('GET', '/account');
        self::assertCount(5, $this->client->getCrawler()->filter('.list-card'));
        self::assertSelectorTextContains('.list', "\u{2212}350,00");
        self::assertSelectorTextContains('.list', '$');

        // Récurrences : active, en pause, terminée, à venir, dans une autre devise.
        $this->client->request('GET', '/recurring');
        self::assertSelectorExists('.badge-paused');
        self::assertSelectorExists('.badge-ended');
        self::assertSelectorTextContains('.recurring-list', 'Prochaine échéance');
        self::assertSelectorTextContains('.recurring-list', 'Abonnement fin de mois (le 31)');
        self::assertSelectorTextContains('.recurring-list', 'Abonnement cloud (USD)');
        foreach (['Quotidienne', 'Hebdomadaire', 'Mensuelle', 'Annuelle'] as $frequency) {
            self::assertSelectorTextContains('.recurring-list', $frequency);
        }

        // Objectifs : atteint, à zéro, dépassé, en retard, sans échéance, en dollars.
        $this->client->request('GET', '/goal');
        foreach (['100', '0', 'Objectif dépassé', 'Objectif en retard', 'Nouveau PC (sans échéance, 0 %)'] as $text) {
            self::assertSelectorTextContains('.goals', $text);
        }
        self::assertCount(7, $this->client->getCrawler()->filter('.goals .goal'));

        // Calendrier : échéances à venir le mois suivant, jour courant sélectionné par défaut ce mois-ci.
        $this->client->request('GET', '/calendar?month='.$month->modify('+1 month')->format('Y-m'));
        self::assertSelectorExists('.cal-plan');
        $this->client->request('GET', '/calendar');
        self::assertSelectorExists('a.cal-day.is-today.is-selected');

        // Histoire plus longue que les six mois du graphique.
        $this->client->request('GET', '/transaction');
        self::assertGreaterThan(8, $this->client->getCrawler()->filter('.month-heading')->count());

        // Profil : récapitulatif.
        $crawler = $this->client->request('GET', '/profile');
        $counts = $crawler->filter('.counts li')->each(static fn ($li) => (int) $li->filter('strong')->text());
        self::assertSame(5, $counts[0], 'comptes');
        self::assertGreaterThan(100, $counts[1], 'transactions');
        self::assertSame(12, $counts[2], 'récurrences');
        self::assertSame(10, $counts[3], 'budgets');
        self::assertSame(7, $counts[4], 'objectifs');
        self::assertSame(14, $counts[5], 'catégories');
    }

    public function testHtmlInUserDataIsEscaped(): void
    {
        $this->load(TestUserFixtures::class);
        $user = $this->loginAs(TestUserFixtures::EMAIL);
        $special = $this->manager->getRepository(Transaction::class)->findOneBy(['amount' => '1.00', 'recurringTransaction' => null]);
        self::assertNotNull($special);
        self::assertTrue(str_contains((string) $special->getLabel(), '<b>HTML</b>'));

        foreach (['/transaction', '/transaction/'.$special->getId(), '/transaction/'.$special->getId().'/edit', '/calendar?day='.$special->getDate()->format('Y-m-d').'&month='.$special->getDate()->format('Y-m')] as $url) {
            $this->client->request('GET', $url);
            $content = (string) $this->client->getResponse()->getContent();
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('<b>HTML</b>', $content, $url);
            self::assertStringNotContainsString('<script>alert', $content, $url);
        }

        $this->client->request('GET', '/transaction/'.$special->getId());
        self::assertStringContainsString('&lt;b&gt;HTML&lt;/b&gt;', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('&lt;script&gt;', (string) $this->client->getResponse()->getContent());
        self::assertNotNull($user);
    }

    public function testTheOtherUserOnlySeesHisOwnData(): void
    {
        $this->load(TestUserFixtures::class);
        $user = $this->loginAs(TestUserFixtures::OTHER_EMAIL);

        $contents = $this->crawl($user);

        self::assertStringContainsString('Compte secret (autre utilisateur)', $contents['/account']);
        self::assertStringContainsString('Achat privé (autre utilisateur)', $contents['/transaction']);
        self::assertStringContainsString('Objectif privé (autre utilisateur)', $contents['/goal']);
        self::assertStringContainsString('Récurrence privée (autre utilisateur)', $contents['/recurring']);
        foreach ($contents as $url => $content) {
            // Noms propres au jeu de données du testeur complet (« Compte courant » est aussi un type de compte).
            foreach (['Compte à découvert', 'Livret A', 'Mission freelance', 'Salaire', 'Voyage aux États-Unis', 'Café du jour'] as $private) {
                self::assertStringNotContainsString($private, $content, 'Donnée de l\'utilisateur complet visible sur '.$url);
            }
        }
    }

    public function testTheOtherUsersItemsAreForbiddenToTheFullUser(): void
    {
        $this->load(TestUserFixtures::class);
        $other = $this->manager->getRepository(User::class)->findOneBy(['email' => TestUserFixtures::OTHER_EMAIL]);
        $urls = $this->pagesOf($other);
        $this->loginAs(TestUserFixtures::EMAIL);

        $forbidden = 0;
        foreach ($urls as $url) {
            if (!preg_match('#^/(account|category|goal|transaction|budget|recurring)/\d+#', $url)) {
                continue;
            }
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
            ++$forbidden;
        }
        self::assertGreaterThan(10, $forbidden);
    }

    public function testEveryPageRendersForTheUserWithoutData(): void
    {
        $this->load(TestUserFixtures::class);
        $user = $this->loginAs(TestUserFixtures::EMPTY_EMAIL);

        $contents = $this->crawl($user);

        // Les listes affichent un message vide plutôt que de planter ou de rester blanches.
        foreach (['/account' => 'Aucun compte', '/transaction' => 'Aucune transaction', '/recurring' => 'Aucune récurrence', '/budget' => 'Aucun budget', '/goal' => 'Aucun objectif', '/category' => 'Aucune catégorie'] as $url => $message) {
            self::assertStringContainsString($message, $contents[$url], $url);
        }
        self::assertStringContainsString('Aucune dépense', $contents['/']);
        self::assertStringContainsString('Aucune transaction', $contents['/stats']);

        $crawler = $this->client->request('GET', '/profile');
        self::assertSame([0, 0, 0, 0, 0, 0], $crawler->filter('.counts li')->each(static fn ($li) => (int) $li->filter('strong')->text()));
    }

    public function testEveryPageRendersForTheDemoUser(): void
    {
        $this->load(DemoFixtures::class);
        $user = $this->loginAs(DemoFixtures::EMAIL);

        $contents = $this->crawl($user);

        self::assertGreaterThan(60, \count($contents));
        self::assertStringContainsString('Compte courant', $contents['/account']);
    }

    public function testReloadingTheFixturesIsIdempotent(): void
    {
        $this->load(TestUserFixtures::class);
        $count = fn (string $class): int => $this->manager->getRepository($class)->count([]);
        $first = [$count(User::class), $count(Account::class), $count(Category::class), $count(Goal::class), $count(Budget::class), $count(RecurringTransaction::class)];

        $this->load(TestUserFixtures::class);

        self::assertSame($first, [$count(User::class), $count(Account::class), $count(Category::class), $count(Goal::class), $count(Budget::class), $count(RecurringTransaction::class)]);
        self::assertSame(1, $this->manager->getRepository(User::class)->count(['email' => TestUserFixtures::EMAIL]));
    }
}
