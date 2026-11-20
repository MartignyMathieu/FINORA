<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\TransactionType;

/** Actions en lot sur les transactions : supprimer, changer de catégorie, changer de compte, avec annulation. */
final class TransactionBulkTest extends AbstractCrudTestCase
{
    private Account $courant;
    private Account $livret;
    private Category $food;
    private Category $leisure;
    /** @var array<string, Transaction> */
    private array $tx = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->courant = $this->createAccount('Compte courant');
        $this->livret = $this->createAccount('Livret A');
        $this->food = $this->createCategory('Alimentation');
        $this->leisure = $this->createCategory('Loisirs');
        $this->tx['courses'] = $this->createDatedTransaction($this->courant, $this->food, TransactionType::Expense, '85.40', '2026-11-18', 'Courses');
        $this->tx['cinema'] = $this->createDatedTransaction($this->courant, $this->leisure, TransactionType::Expense, '12.00', '2026-11-10', 'Cinéma');
        $this->tx['salaire'] = $this->createDatedTransaction($this->courant, $this->food, TransactionType::Income, '2500.00', '2026-11-03', 'Salaire');
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/transaction');

        return (string) $crawler->filter('#bulk-form input[name="_token"]')->attr('value');
    }

    /** @param list<string> $keys clés de $this->tx */
    private function post(string $action, array $keys, array $extra = [], string $return = ''): void
    {
        $token = $this->token();
        $this->client->request('POST', '/bulk/transactions', [
            'action' => $action,
            'ids' => array_map(fn (string $key): string => (string) $this->tx[$key]->getId(), $keys),
            '_token' => $token,
            'return' => $return,
        ] + $extra);
    }

    /** @return array<string, string> libellé => nom de la catégorie, relu en base */
    private function categories(): array
    {
        $this->manager->clear();
        $result = [];
        foreach ($this->manager->getRepository(Transaction::class)->findAll() as $t) {
            $result[$t->getLabel()] = $t->getCategory()->getName();
        }

        return $result;
    }

    private function total(): int
    {
        return $this->manager->getRepository(Transaction::class)->count([]);
    }

    public function testEachRowHasACheckboxAndTheBarOffersTheActions(): void
    {
        $crawler = $this->client->request('GET', '/transaction');

        self::assertCount(3, $crawler->filter('.tx-row input[type="checkbox"][name="ids[]"][form="bulk-form"]'));
        self::assertSelectorExists('.bulk-all input[data-bulk-target="all"]');
        self::assertSelectorExists('form#bulk-form[action="/bulk/transactions"][data-bulk-target="bar"]');
        self::assertSelectorExists('#bulk-form button[name="action"][value="delete"]');
        self::assertSelectorExists('#bulk-form button[name="action"][value="category"][disabled]');
        self::assertSelectorExists('#bulk-form button[name="action"][value="account"][disabled]');
        $categories = $crawler->filter('#bulk-form select[name="category"] option')->each(static fn ($o): string => $o->text());
        self::assertContains('Loisirs', $categories);
        self::assertSelectorExists('.bulk-scope[data-controller="bulk"]');
    }

    public function testNoBarWhenThereIsNothingToSelect(): void
    {
        $this->client->request('GET', '/transaction?min=99999');

        self::assertSelectorNotExists('#bulk-form');
        self::assertSelectorNotExists('.bulk-all');
    }

    public function testDeletingTheSelectionKeepsTheOthersAndCanBeUndone(): void
    {
        $this->post('delete', ['courses', 'cinema']);

        self::assertResponseRedirects('/transaction');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-undo', '2 transactions supprimées.');
        self::assertSame(1, $this->total());
        self::assertSame(['Salaire'], array_keys($this->categories()));

        $this->client->submit($crawler->filter('.flash-undo form')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', '2 transactions restaurées');
        self::assertEqualsCanonicalizing(['Courses', 'Cinéma', 'Salaire'], array_keys($this->categories()));
        self::assertSame(3, $this->total());
    }

    public function testMovingTheSelectionToAnotherCategory(): void
    {
        $this->post('category', ['courses', 'cinema'], ['category' => (string) $this->food->getId()]);

        self::assertResponseRedirects('/transaction');
        $this->client->followRedirect();
        // « Courses » y était déjà : une seule ligne est comptée comme modifiée.
        self::assertSelectorTextContains('.flash-undo', '1 transaction déplacée vers « Alimentation ».');
        self::assertEquals(['Courses' => 'Alimentation', 'Cinéma' => 'Alimentation', 'Salaire' => 'Alimentation'], $this->categories());
    }

    public function testMovingToTheSameCategoryChangesNothing(): void
    {
        $this->post('category', ['courses', 'salaire'], ['category' => (string) $this->food->getId()]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-success', 'Rien à modifier');
        self::assertSelectorNotExists('.flash-undo');
    }

    public function testACategoryMoveCanBeUndone(): void
    {
        $this->post('category', ['courses', 'cinema'], ['category' => (string) $this->leisure->getId()]);
        $crawler = $this->client->followRedirect();
        self::assertSame('Loisirs', $this->categories()['Courses']);

        $this->client->submit($crawler->filter('.flash-undo form')->form());
        self::assertResponseRedirects('/transaction');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-success', 'Modification annulée : 1 transaction rétablie.');
        self::assertEquals(['Courses' => 'Alimentation', 'Cinéma' => 'Loisirs', 'Salaire' => 'Alimentation'], $this->categories());
    }

    public function testMovingTheSelectionToAnotherAccountOfTheSameCurrency(): void
    {
        $this->post('account', ['courses', 'cinema'], ['account' => (string) $this->livret->getId()]);

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-undo', '2 transactions déplacées vers « Livret A ».');
        $this->manager->clear();
        $accounts = [];
        foreach ($this->manager->getRepository(Transaction::class)->findAll() as $t) {
            $accounts[$t->getLabel()] = $t->getAccount()->getName();
        }
        self::assertEquals(['Courses' => 'Livret A', 'Cinéma' => 'Livret A', 'Salaire' => 'Compte courant'], $accounts);

        // Annuler remet les deux lignes sur le compte courant.
        $this->client->submit($crawler->filter('.flash-undo form')->form());
        $this->manager->clear();
        foreach ($this->manager->getRepository(Transaction::class)->findAll() as $t) {
            self::assertSame('Compte courant', $t->getAccount()->getName(), $t->getLabel());
        }
    }

    public function testTransactionsInAnotherCurrencyStayInPlace(): void
    {
        $usd = $this->createAccount('Voyage', 'USD');
        $this->tx['hotel'] = $this->createDatedTransaction($usd, $this->leisure, TransactionType::Expense, '90.00', '2026-11-12', 'Hôtel');

        $this->post('account', ['courses', 'hotel'], ['account' => (string) $this->livret->getId()]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-undo', '1 transaction déplacée vers « Livret A ».');
        self::assertSelectorTextContains('.flash-undo', '1 autre restée en place (devise différente)');
        $this->manager->clear();
        self::assertSame('Voyage', $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Hôtel'])->getAccount()->getName());
    }

    public function testOnlyForeignCurrenciesMeansAnErrorAndNoChange(): void
    {
        $usd = $this->createAccount('Voyage', 'USD');
        $this->tx['hotel'] = $this->createDatedTransaction($usd, $this->leisure, TransactionType::Expense, '90.00', '2026-11-12', 'Hôtel');

        $this->post('account', ['hotel'], ['account' => (string) $this->livret->getId()]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'Aucune transaction déplacée');
        self::assertSelectorTextContains('.flash-error', 'autre devise');
    }

    public function testADestinationMustBeChosen(): void
    {
        $this->post('category', ['courses']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Choisissez la catégorie de destination');

        $this->post('account', ['courses']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Choisissez le compte de destination');
        self::assertEquals(['Courses' => 'Alimentation', 'Cinéma' => 'Loisirs', 'Salaire' => 'Alimentation'], $this->categories());
    }

    public function testAnotherUsersTransactionsAndDestinationsAreNeverTouched(): void
    {
        $bob = $this->createUser('bob@example.test', 'Bob');
        $bobAccount = $this->createAccount('Compte de Bob', 'EUR', $bob);
        $bobCategory = $this->createCategory('Secret', null, $bob);
        $theirs = $this->createDatedTransaction($bobAccount, $bobCategory, TransactionType::Expense, '77.00', '2026-11-10', 'Achat de Bob');

        // Sélection mêlant une transaction à moi et une à Bob : seule la mienne est supprimée.
        $token = $this->token();
        $this->client->request('POST', '/bulk/transactions', ['action' => 'delete', 'ids' => [(string) $this->tx['cinema']->getId(), (string) $theirs->getId()], '_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-undo', '1 transaction supprimée');
        self::assertNotNull($this->manager->getRepository(Transaction::class)->find($theirs->getId()));

        // Catégorie et compte de Bob refusés comme destinations.
        $this->post('category', ['courses'], ['category' => (string) $bobCategory->getId()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Choisissez la catégorie');
        $this->post('account', ['courses'], ['account' => (string) $bobAccount->getId()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Choisissez le compte');

        // Seulement des transactions de Bob : rien à modifier.
        $token = $this->token();
        $this->client->request('POST', '/bulk/transactions', ['action' => 'delete', 'ids' => [(string) $theirs->getId()], '_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Aucune transaction à modifier');
        self::assertNotNull($this->manager->getRepository(Transaction::class)->find($theirs->getId()));
    }

    public function testInvalidRequestsAreRejected(): void
    {
        $token = $this->token();
        $id = (string) $this->tx['courses']->getId();

        foreach ([
            'aucune sélection' => ['action' => 'delete', '_token' => $token],
            'action inconnue' => ['action' => 'exploser', 'ids' => [$id], '_token' => $token],
            'identifiant invalide' => ['action' => 'delete', 'ids' => ['abc'], '_token' => $token],
            'trop de lignes' => ['action' => 'delete', 'ids' => array_map('strval', range(1, 301)), '_token' => $token],
            'sans jeton' => ['action' => 'delete', 'ids' => [$id]],
        ] as $label => $payload) {
            $this->client->request('POST', '/bulk/transactions', $payload);
            self::assertResponseStatusCodeSame(422, $label);
        }
        self::assertSame(3, $this->total());
    }

    public function testAWrongSecurityTokenDoesNothing(): void
    {
        $this->client->request('POST', '/bulk/transactions', ['action' => 'delete', 'ids' => [(string) $this->tx['courses']->getId()], '_token' => 'faux']);

        self::assertResponseRedirects('/transaction');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'la page a expiré');
        self::assertSame(3, $this->total());
    }

    public function testTheReturnAddressKeepsTheFiltersAndDropsAnythingElse(): void
    {
        $this->post('delete', ['cinema'], [], 'type=expense&from=2026-11-01&account='.$this->courant->getId().'&evil=http%3A%2F%2Fexemple.fr&min=abc');

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/transaction?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame(['type' => 'expense', 'from' => '2026-11-01', 'account' => (string) $this->courant->getId()], $query);
        self::assertStringNotContainsString('evil', $location);
    }

    public function testTheSameIdSentTwiceIsCountedOnce(): void
    {
        $token = $this->token();
        $id = (string) $this->tx['cinema']->getId();
        $this->client->request('POST', '/bulk/transactions', ['action' => 'delete', 'ids' => [$id, $id], '_token' => $token]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-undo', '1 transaction supprimée.');
    }

    public function testTheBulkRouteRequiresALogin(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('POST', '/bulk/transactions', ['action' => 'delete', 'ids' => ['1']]);

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testTheUndoOfABulkEditIsSingleUse(): void
    {
        $this->post('category', ['cinema'], ['category' => (string) $this->food->getId()]);
        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('.flash-undo form')->form();

        $this->client->submit($form);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'délai pour annuler');
    }
}
