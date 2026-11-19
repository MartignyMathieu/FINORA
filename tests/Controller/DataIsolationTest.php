<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionType;

/** Un utilisateur ne voit ni ne touche jamais les données d'un autre. */
final class DataIsolationTest extends AbstractCrudTestCase
{
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bob = $this->createUser('bob@example.test', 'Bob');
    }

    private function loginAsBob(): void
    {
        $this->client->loginUser($this->bob);
    }

    public function testOtherUsersItemsAreForbidden(): void
    {
        $account = $this->createAccount('Compte d\'Alice');
        $category = $this->createCategory('Catégorie d\'Alice');
        $goal = $this->createGoal('Objectif d\'Alice');
        $transaction = $this->createTransaction($account, $category);
        $budget = $this->createBudget($category);
        $this->loginAsBob();

        foreach ([
            '/account/'.$account->getId(),
            '/account/'.$account->getId().'/edit',
            '/category/'.$category->getId(),
            '/category/'.$category->getId().'/edit',
            '/goal/'.$goal->getId(),
            '/goal/'.$goal->getId().'/edit',
            '/transaction/'.$transaction->getId(),
            '/transaction/'.$transaction->getId().'/edit',
            '/budget/'.$budget->getId(),
            '/budget/'.$budget->getId().'/edit',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, 'GET '.$url);
        }
    }

    public function testOtherUsersItemsCannotBeDeleted(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $goal = $this->createGoal();
        $transaction = $this->createTransaction($account, $category);
        $budget = $this->createBudget($category);
        $this->loginAsBob();

        foreach ([
            '/transaction/'.$transaction->getId(),
            '/budget/'.$budget->getId(),
            '/goal/'.$goal->getId(),
            '/account/'.$account->getId(),
            '/category/'.$category->getId(),
        ] as $url) {
            $this->client->request('POST', $url, ['_token' => 'peu-importe']);
            self::assertResponseStatusCodeSame(403, 'POST '.$url);
        }

        $this->manager->clear();
        foreach ([Transaction::class, Budget::class, Goal::class, Account::class, Category::class] as $class) {
            self::assertSame(1, $this->manager->getRepository($class)->count([]), $class.' doit toujours exister');
        }
    }

    public function testListsOnlyShowTheCurrentUsersData(): void
    {
        $account = $this->createAccount('Compte d\'Alice');
        $category = $this->createCategory('Catégorie d\'Alice');
        $this->createGoal('Objectif d\'Alice');
        $this->createTransaction($account, $category, 'Achat d\'Alice');
        $this->createBudget($category);

        $this->loginAsBob();
        $this->createAccount('Compte de Bob', 'EUR', $this->bob);

        foreach (['/account', '/transaction', '/budget?month=2026-11', '/goal', '/category'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('d\'Alice', $this->client->getResponse()->getContent(), $url);
        }

        $this->client->request('GET', '/account');
        self::assertSelectorTextContains('.list', 'Compte de Bob');
    }

    public function testTotalsOnlyCoverTheCurrentUsersData(): void
    {
        $account = $this->createAccount();
        $account->setInitialBalance('1000.00');
        $this->manager->flush();
        $this->createDatedTransaction($account, $this->createCategory(), TransactionType::Expense, '250.00');

        $this->loginAsBob();

        $this->client->request('GET', '/?month=2026-11');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kpis', '0,00');
        self::assertSelectorTextNotContains('.kpis', "750,00");
        self::assertSelectorTextContains('.grid-2', 'Aucune dépense');

        $this->client->request('GET', '/stats?month=2026-11');
        self::assertSelectorTextContains('.card', 'Aucune transaction');
    }

    public function testFormsOnlyOfferTheCurrentUsersAccountsAndCategories(): void
    {
        $this->createAccount('Compte d\'Alice');
        $this->createCategory('Catégorie d\'Alice');
        $this->loginAsBob();
        $this->createAccount('Compte de Bob', 'EUR', $this->bob);
        $this->createCategory('Catégorie de Bob', null, $this->bob);

        foreach (['/transaction/new', '/budget/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertStringNotContainsString('d\'Alice', $this->client->getResponse()->getContent(), $url);
            self::assertStringContainsString('Catégorie de Bob', $this->client->getResponse()->getContent(), $url);
        }
    }

    public function testCannotAttachATransactionToSomeoneElsesAccountOrCategory(): void
    {
        $alicesAccount = $this->createAccount('Compte d\'Alice');
        $alicesCategory = $this->createCategory('Catégorie d\'Alice');
        $this->loginAsBob();
        $bobsAccount = $this->createAccount('Compte de Bob', 'EUR', $this->bob);
        $bobsCategory = $this->createCategory('Catégorie de Bob', null, $this->bob);

        $this->client->request('GET', '/transaction/new');
        $form = $this->client->getCrawler()->selectButton('Enregistrer')->form([
            'transaction[label]' => 'Intrusion',
            'transaction[amount]' => '10',
            'transaction[type]' => TransactionType::Expense->value,
            'transaction[date]' => '2026-11-02',
            'transaction[account]' => (string) $bobsAccount->getId(),
            'transaction[category]' => (string) $bobsCategory->getId(),
        ]);
        // Le navigateur n'autoriserait pas ces valeurs : on forge la requête.
        $values = $form->getPhpValues();
        $values['transaction']['account'] = (string) $alicesAccount->getId();
        $this->client->request('POST', '/transaction/new', $values, [], [], null);
        self::assertResponseStatusCodeSame(422);

        $values['transaction']['account'] = (string) $bobsAccount->getId();
        $values['transaction']['category'] = (string) $alicesCategory->getId();
        $this->client->request('POST', '/transaction/new', $values);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testCreatedDataBelongsToTheCurrentUser(): void
    {
        $this->client->request('GET', '/account/new');
        $this->client->submitForm('Enregistrer', ['account[name]' => 'Nouveau', 'account[currency]' => 'EUR', 'account[initialBalance]' => '0']);
        $this->client->request('GET', '/goal/new');
        $this->client->submitForm('Enregistrer', ['goal[name]' => 'Projet', 'goal[targetAmount]' => '100', 'goal[savedAmount]' => '0', 'goal[currency]' => 'EUR']);
        $this->client->request('GET', '/category/new');
        $this->client->submitForm('Enregistrer', ['category[name]' => 'Nouvelle']);

        $this->manager->clear();
        foreach ([Account::class, Goal::class, Category::class] as $class) {
            $entity = $this->manager->getRepository($class)->findOneBy([]);
            self::assertNotNull($entity, $class);
            self::assertSame($this->user->getId(), $entity->getOwner()->getId(), $class);
        }
    }

    public function testTwoUsersCanUseTheSameCategoryName(): void
    {
        $this->createCategory('Transport');
        $this->loginAsBob();

        $this->client->request('GET', '/category/new');
        $this->client->submitForm('Enregistrer', ['category[name]' => 'Transport']);

        self::assertResponseRedirects('/category');
        self::assertSame(2, $this->manager->getRepository(Category::class)->count(['name' => 'Transport']));
    }
}
