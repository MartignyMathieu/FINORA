<?php

namespace App\Tests\Controller;

use App\Entity\Account;

final class AccountControllerTest extends AbstractCrudTestCase
{
    public function testIndex(): void
    {
        $this->createAccount('Livret A');

        $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Livret A');
    }

    public function testNew(): void
    {
        $this->client->request('GET', '/account/new');
        $this->client->submitForm('Save', [
            'account[name]' => 'Compte joint',
            'account[currency]' => 'USD',
            'account[initialBalance]' => '150.5',
        ]);

        self::assertResponseRedirects('/account');

        $account = $this->manager->getRepository(Account::class)->findOneBy(['name' => 'Compte joint']);
        self::assertNotNull($account);
        self::assertSame('USD', $account->getCurrency());
        self::assertSame('150.50', $account->getInitialBalance());
    }

    public function testNewWithBlankNameIsRejected(): void
    {
        $this->client->request('GET', '/account/new');
        $this->client->submitForm('Save', ['account[name]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(Account::class)->count([]));
    }

    public function testShow(): void
    {
        $account = $this->createAccount('Compte courant');

        $this->client->request('GET', '/account/'.$account->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Compte courant');
    }

    public function testEdit(): void
    {
        $account = $this->createAccount();

        $this->client->request('GET', sprintf('/account/%d/edit', $account->getId()));
        $this->client->submitForm('Update', ['account[name]' => 'Renommé']);

        self::assertResponseRedirects('/account');
        $this->manager->clear();
        self::assertSame('Renommé', $this->manager->find(Account::class, $account->getId())->getName());
    }

    public function testRemove(): void
    {
        $account = $this->createAccount();

        $this->client->request('GET', '/account/'.$account->getId());
        $this->client->submitForm('Delete');

        self::assertResponseRedirects('/account');
        self::assertSame(0, $this->manager->getRepository(Account::class)->count([]));
    }

    public function testRemoveIsRefusedWhenAccountHasTransactions(): void
    {
        $account = $this->createAccount();
        $this->createTransaction($account, $this->createCategory());
        $this->manager->clear();

        $this->client->request('GET', '/account/'.$account->getId());
        $this->client->submitForm('Delete');

        self::assertResponseRedirects('/account/'.$account->getId());
        self::assertSame(1, $this->manager->getRepository(Account::class)->count([]));
    }
}
