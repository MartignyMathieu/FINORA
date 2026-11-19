<?php

namespace App\Tests\Controller;

use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;

final class RecurringTransactionControllerTest extends AbstractCrudTestCase
{
    /** @return array<string, string> Valeurs d'un formulaire de récurrence valide. */
    private function formValues(int $accountId, int $categoryId, array $overrides = []): array
    {
        return $overrides + [
            'recurring_transaction[label]' => 'Abonnement',
            'recurring_transaction[amount]' => '12.99',
            'recurring_transaction[type]' => TransactionType::Expense->value,
            'recurring_transaction[account]' => (string) $accountId,
            'recurring_transaction[category]' => (string) $categoryId,
            'recurring_transaction[frequency]' => RecurrenceFrequency::Monthly->value,
            'recurring_transaction[startDate]' => '2099-01-15',
        ];
    }

    public function testIndexListsRulesWithTheirNextDueDate(): void
    {
        $this->createRecurring($this->createAccount('Compte courant'), $this->createCategory('Logement'), '2099-03-05', label: 'Loyer');

        $this->client->request('GET', '/recurring');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.recurring-list', 'Loyer');
        self::assertSelectorTextContains('.recurring-list', 'Mensuelle');
        self::assertSelectorTextContains('.recurring-list', 'Logement');
        self::assertSelectorTextContains('.recurring-list', 'Prochaine échéance : 5 mars 2099');
        self::assertSelectorTextContains('.recurring-list', "\u{2212}500,00");
    }

    public function testIndexShowsMonthlyEquivalentTotals(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createRecurring($account, $category, '2099-01-01', RecurrenceFrequency::Monthly, '500.00', TransactionType::Expense, 'Loyer');
        $this->createRecurring($account, $category, '2099-01-01', RecurrenceFrequency::Yearly, '1200.00', TransactionType::Expense, 'Assurance');
        $this->createRecurring($account, $category, '2099-01-01', RecurrenceFrequency::Monthly, '2500.00', TransactionType::Income, 'Salaire');
        // Ignorées : en pause et terminée.
        $this->createRecurring($account, $category, '2099-01-01', RecurrenceFrequency::Monthly, '999.00', TransactionType::Expense, 'Pause', active: false);
        $this->createRecurring($account, $category, '2020-01-01', RecurrenceFrequency::Monthly, '888.00', TransactionType::Expense, 'Fini', end: '2020-03-01', lastGenerated: '2020-03-01');

        $this->client->request('GET', '/recurring');

        self::assertSelectorTextContains('.kpis', "2\u{202F}500,00");   // revenus : salaire
        self::assertSelectorTextContains('.kpis', "600,00");            // dépenses : 500 + 1200/12
        self::assertSelectorTextNotContains('.kpis', "999");
        self::assertSelectorTextNotContains('.kpis', "888");
    }

    public function testIndexFlagsPausedAndEndedRules(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createRecurring($account, $category, '2099-01-01', label: 'En pause', active: false);
        $this->createRecurring($account, $category, '2020-01-01', label: 'Terminée', end: '2020-03-01', lastGenerated: '2020-03-01');

        $this->client->request('GET', '/recurring');

        self::assertSelectorTextContains('.badge-paused', 'En pause');
        self::assertSelectorTextContains('.badge-ended', 'Terminée');
    }

    public function testIndexIsEmptyByDefault(): void
    {
        $this->client->request('GET', '/recurring');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.recurring-list', 'Aucune récurrence');
    }

    public function testNewWithAFutureStartGeneratesNothingYet(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();

        $this->client->request('GET', '/recurring/new');
        $this->client->submitForm('Enregistrer', $this->formValues($account->getId(), $category->getId(), [
            'recurring_transaction[note]' => 'Streaming',
        ]));

        self::assertResponseRedirects('/recurring');
        $rule = $this->manager->getRepository(RecurringTransaction::class)->findOneBy(['label' => 'Abonnement']);
        self::assertNotNull($rule);
        self::assertSame('12.99', $rule->getAmount());
        self::assertSame(RecurrenceFrequency::Monthly, $rule->getFrequency());
        self::assertSame('2099-01-15', $rule->getStartDate()->format('Y-m-d'));
        self::assertNull($rule->getEndDate());
        self::assertTrue($rule->isActive());
        self::assertSame($this->user->getId(), $rule->getOwner()->getId());
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testNewWithAPastStartGeneratesThePastOccurrencesImmediately(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $start = (new \DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');

        $this->client->request('GET', '/recurring/new');
        $this->client->submitForm('Enregistrer', $this->formValues($account->getId(), $category->getId(), [
            'recurring_transaction[frequency]' => RecurrenceFrequency::Daily->value,
            'recurring_transaction[startDate]' => $start,
        ]));

        self::assertResponseRedirects('/recurring');
        self::assertSame(3, $this->manager->getRepository(Transaction::class)->count([]));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', '3 transactions ont été générées');
    }

    public function testNewRejectsInvalidData(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();

        foreach ([
            'montant négatif' => ['recurring_transaction[amount]' => '-5'],
            'montant nul' => ['recurring_transaction[amount]' => '0'],
            'libellé vide' => ['recurring_transaction[label]' => ''],
            'fin avant le début' => ['recurring_transaction[endDate]' => '2098-12-31'],
        ] as $case => $overrides) {
            $this->client->request('GET', '/recurring/new');
            $this->client->submitForm('Enregistrer', $this->formValues($account->getId(), $category->getId(), $overrides));
            self::assertResponseStatusCodeSame(422, $case);
        }

        self::assertSame(0, $this->manager->getRepository(RecurringTransaction::class)->count([]));
    }

    public function testEdit(): void
    {
        $account = $this->createAccount();
        $rule = $this->createRecurring($account, $this->createCategory(), '2099-01-01', label: 'Ancien');

        $this->client->request('GET', sprintf('/recurring/%d/edit', $rule->getId()));
        $this->client->submitForm('Mettre à jour', [
            'recurring_transaction[label]' => 'Nouveau',
            'recurring_transaction[amount]' => '650',
            'recurring_transaction[endDate]' => '2100-01-01',
        ]);

        self::assertResponseRedirects('/recurring');
        $this->manager->clear();
        $rule = $this->manager->find(RecurringTransaction::class, $rule->getId());
        self::assertSame('Nouveau', $rule->getLabel());
        self::assertSame('650.00', $rule->getAmount());
        self::assertSame('2100-01-01', $rule->getEndDate()->format('Y-m-d'));
    }

    public function testEditingARuleLeavesAlreadyGeneratedTransactionsUntouched(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory(), '2026-09-05', label: 'Loyer');
        static::getContainer()->get(\App\Service\RecurringTransactionGenerator::class)->generateFor($rule, new \DateTimeImmutable('2026-10-20'));

        $this->client->request('GET', sprintf('/recurring/%d/edit', $rule->getId()));
        $this->client->submitForm('Mettre à jour', ['recurring_transaction[amount]' => '999']);

        // Les deux transactions déjà générées gardent l'ancien montant ; l'échéance suivante (générée à
        // l'enregistrement) utilise le nouveau.
        $this->manager->clear();
        $amounts = array_map(
            static fn (Transaction $t): string => $t->getAmount(),
            $this->manager->getRepository(Transaction::class)->findBy(['label' => 'Loyer'], ['date' => 'ASC']),
        );
        self::assertSame(['500.00', '500.00', '999.00'], $amounts);
    }

    public function testPausingAndResumingARule(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory(), '2099-01-01');

        $crawler = $this->client->request('GET', '/recurring');
        $this->client->submit($crawler->filter('form[action$="/toggle"]')->form());
        self::assertResponseRedirects('/recurring');
        $this->manager->clear();
        self::assertFalse($this->manager->find(RecurringTransaction::class, $rule->getId())->isActive());

        $crawler = $this->client->request('GET', '/recurring');
        self::assertSelectorTextContains('.badge-paused', 'En pause');
        $this->client->submit($crawler->filter('form[action$="/toggle"]')->form());
        $this->manager->clear();
        self::assertTrue($this->manager->find(RecurringTransaction::class, $rule->getId())->isActive());
    }

    public function testResumingCatchesUpMissedOccurrences(): void
    {
        $start = (new \DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');
        $this->createRecurring($this->createAccount(), $this->createCategory(), $start, RecurrenceFrequency::Daily, active: false);

        $crawler = $this->client->request('GET', '/recurring');
        $this->client->submit($crawler->filter('form[action$="/toggle"]')->form());

        self::assertSame(3, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testDeletingARuleKeepsItsTransactions(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory(), '2026-09-05');
        static::getContainer()->get(\App\Service\RecurringTransactionGenerator::class)->generateFor($rule, new \DateTimeImmutable('2026-10-20'));
        $this->manager->clear();

        $this->client->request('GET', sprintf('/recurring/%d/edit', $rule->getId()));
        $this->client->submitForm('Supprimer');

        self::assertResponseRedirects('/recurring');
        self::assertSame(0, $this->manager->getRepository(RecurringTransaction::class)->count([]));
        self::assertSame(2, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testGeneratedTransactionsAreTaggedInTheTransactionPages(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory(), '2026-10-05', label: 'Loyer');
        static::getContainer()->get(\App\Service\RecurringTransactionGenerator::class)->generateFor($rule, new \DateTimeImmutable('2026-10-20'));
        $this->manager->clear();
        $transaction = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Loyer']);

        $this->client->request('GET', '/transaction');
        self::assertSelectorTextContains('.tx-list .recurring-tag', 'mensuelle');

        $this->client->request('GET', '/transaction/'.$transaction->getId());
        self::assertSelectorTextContains('table', 'Générée par la récurrence « Loyer »');
    }

    public function testTheNavigationLinksToRecurringTransactions(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertGreaterThan(0, $crawler->filter('.sidebar a[href="/recurring"]')->count());
    }

    public function testFormsOnlyOfferTheCurrentUsersAccountsAndCategories(): void
    {
        $bob = $this->createUser('bob@example.test', 'Bob');
        $this->createAccount('Compte de Bob', 'EUR', $bob);
        $this->createCategory('Catégorie de Bob', null, $bob);
        $this->createAccount('Mon compte');

        $this->client->request('GET', '/recurring/new');

        self::assertStringNotContainsString('de Bob', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Mon compte', $this->client->getResponse()->getContent());
    }

    public function testCannotCreateARuleOnSomeoneElsesAccount(): void
    {
        $bob = $this->createUser('bob@example.test', 'Bob');
        $bobsAccount = $this->createAccount('Compte de Bob', 'EUR', $bob);
        $myCategory = $this->createCategory();
        $myAccount = $this->createAccount('Mon compte');

        $this->client->request('GET', '/recurring/new');
        $form = $this->client->getCrawler()->selectButton('Enregistrer')->form($this->formValues($myAccount->getId(), $myCategory->getId()));
        $values = $form->getPhpValues();
        $values['recurring_transaction']['account'] = (string) $bobsAccount->getId();
        $this->client->request('POST', '/recurring/new', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(RecurringTransaction::class)->count([]));
    }

    public function testOtherUsersRulesAreInvisibleAndForbidden(): void
    {
        $bob = $this->createUser('bob@example.test', 'Bob');
        $rule = $this->createRecurring($this->createAccount('Compte d\'Alice'), $this->createCategory(), label: 'Loyer d\'Alice');
        $this->client->loginUser($bob);

        $this->client->request('GET', '/recurring');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('d\'Alice', $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/recurring/%d/edit', $rule->getId()));
        self::assertResponseStatusCodeSame(403);

        foreach (['/recurring/%d/toggle', '/recurring/%d'] as $pattern) {
            $this->client->request('POST', sprintf($pattern, $rule->getId()), ['_token' => 'peu-importe']);
            self::assertResponseStatusCodeSame(403, $pattern);
        }

        $this->manager->clear();
        self::assertTrue($this->manager->find(RecurringTransaction::class, $rule->getId())->isActive());
    }

    public function testRecurringPagesRequireALogin(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        foreach (['/recurring', '/recurring/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', null, $url);
        }
    }
}
