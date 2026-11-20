<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Enum\TransactionType;

/** Virements entre comptes : saisie, validation, soldes, isolation entre utilisateurs. */
final class TransferControllerTest extends AbstractCrudTestCase
{
    public function testIndexListsTheTransfersNewestFirst(): void
    {
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');
        $this->createTransfer($courant, $livret, '50.00', '2026-10-05');
        $this->createTransfer($courant, $livret, '120.00', '2026-11-12');

        $crawler = $this->client->request('GET', '/transfer');

        self::assertResponseIsSuccessful();
        $amounts = $crawler->filter('.transfer-amount')->each(static fn ($n): string => $n->text());
        self::assertSame(["120,00\u{00A0}€", "50,00\u{00A0}€"], $amounts);
        self::assertSelectorTextContains('.tx-list', 'Compte courant');
        self::assertSelectorTextContains('.tx-list', 'Livret A');
    }

    public function testEmptyListInvitesToCreateOne(): void
    {
        $this->client->request('GET', '/transfer');

        self::assertSelectorTextContains('.empty', 'Aucun virement');
    }

    public function testNewTransferMovesMoneyBetweenAccounts(): void
    {
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');

        $this->client->request('GET', '/transfer/new');
        $this->client->submitForm('Enregistrer', [
            'transfer[fromAccount]' => (string) $courant->getId(),
            'transfer[toAccount]' => (string) $livret->getId(),
            'transfer[amount]' => '200.00',
            'transfer[date]' => '2026-11-10',
            'transfer[note]' => 'Épargne',
        ]);

        self::assertResponseRedirects('/transfer');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'virement a été enregistré');

        $this->client->request('GET', '/account');
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("\u{2212}200,00\u{00A0}€", $page, 'Le compte débité baisse de 200 €');
        self::assertStringContainsString("200,00\u{00A0}€", $page, 'Le compte crédité monte de 200 €');
    }

    public function testATransferChangesNeitherTheTotalBalanceNorTheMonthTotals(): void
    {
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');
        $category = $this->createCategory('Revenus');
        $this->createDatedTransaction($courant, $category, TransactionType::Income, '1000.00', '2026-11-03', 'Salaire');

        $before = $this->client->request('GET', '/?month=2026-11')->filter('.kpis')->text();
        $this->createTransfer($courant, $livret, '400.00', '2026-11-10');
        $after = $this->client->request('GET', '/?month=2026-11')->filter('.kpis')->text();

        self::assertSame($before, $after, 'Solde total, revenus, dépenses et épargne du mois sont inchangés');
        self::assertStringContainsString("1\u{202F}000,00", $after);
    }

    public function testAccountBalancesAfterSeveralTransfers(): void
    {
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');
        $this->createTransfer($courant, $livret, '300.00');
        $this->createTransfer($livret, $courant, '50.00');

        $this->client->request('GET', '/account');
        $page = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString("\u{2212}250,00\u{00A0}€", $page, 'Compte courant : −300 + 50');
        self::assertStringContainsString("250,00\u{00A0}€", $page, 'Livret A : +300 − 50');
    }

    public function testTheSameAccountOnBothSidesIsRejected(): void
    {
        $courant = $this->createAccount('Compte courant');

        $this->client->request('GET', '/transfer/new');
        $this->client->submitForm('Enregistrer', [
            'transfer[fromAccount]' => (string) $courant->getId(),
            'transfer[toAccount]' => (string) $courant->getId(),
            'transfer[amount]' => '10.00',
            'transfer[date]' => '2026-11-10',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'deux comptes différents');
        self::assertSame(0, $this->manager->getRepository(Transfer::class)->count([]));
    }

    public function testAccountsInDifferentCurrenciesAreRejected(): void
    {
        $euros = $this->createAccount('Compte courant', 'EUR');
        $dollars = $this->createAccount('Compte voyage', 'USD');

        $this->client->request('GET', '/transfer/new');
        $this->client->submitForm('Enregistrer', [
            'transfer[fromAccount]' => (string) $euros->getId(),
            'transfer[toAccount]' => (string) $dollars->getId(),
            'transfer[amount]' => '10.00',
            'transfer[date]' => '2026-11-10',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'même devise');
        self::assertSame(0, $this->manager->getRepository(Transfer::class)->count([]));
    }

    public function testNonPositiveAmountsAreRejected(): void
    {
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');

        foreach (['0', '-20.00'] as $amount) {
            $this->client->request('GET', '/transfer/new');
            $this->client->submitForm('Enregistrer', [
                'transfer[fromAccount]' => (string) $courant->getId(),
                'transfer[toAccount]' => (string) $livret->getId(),
                'transfer[amount]' => $amount,
                'transfer[date]' => '2026-11-10',
            ]);
            self::assertResponseStatusCodeSame(422, $amount);
        }
        self::assertSame(0, $this->manager->getRepository(Transfer::class)->count([]));
    }

    public function testTheFormOffersOnlyTheUsersOwnAccounts(): void
    {
        $this->createAccount('Mon compte');
        $other = $this->createUser('bob@example.test', 'Bob');
        $this->createAccount('Compte de Bob', 'EUR', $other);

        $crawler = $this->client->request('GET', '/transfer/new');

        $options = $crawler->filter('#transfer_fromAccount option')->each(static fn ($o): string => $o->text());
        self::assertContains('Mon compte (EUR)', $options);
        self::assertNotContains('Compte de Bob (EUR)', $options);
    }

    public function testAnotherUsersAccountCannotBeUsedEvenWithAForgedRequest(): void
    {
        $mine = $this->createAccount('Mon compte');
        $other = $this->createUser('bob@example.test', 'Bob');
        $theirs = $this->createAccount('Compte de Bob', 'EUR', $other);

        $crawler = $this->client->request('GET', '/transfer/new');
        $form = $crawler->selectButton('Enregistrer')->form([
            'transfer[amount]' => '10.00',
            'transfer[date]' => '2026-11-10',
        ]);
        $form['transfer[fromAccount]']->select((string) $mine->getId());
        $form['transfer[toAccount]']->disableValidation()->setValue((string) $theirs->getId());
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(Transfer::class)->count([]));
    }

    public function testEditAndDelete(): void
    {
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');
        $transfer = $this->createTransfer($courant, $livret, '100.00');

        $this->client->request('GET', '/transfer/'.$transfer->getId().'/edit');
        $this->client->submitForm('Mettre à jour', ['transfer[amount]' => '175.50']);
        self::assertResponseRedirects('/transfer');
        $this->manager->clear();
        self::assertSame('175.50', $this->manager->getRepository(Transfer::class)->find($transfer->getId())->getAmount());

        $this->client->request('GET', '/transfer/'.$transfer->getId().'/edit');
        $this->client->submitForm('Supprimer');
        self::assertResponseRedirects('/transfer');
        $this->manager->clear();
        self::assertSame(0, $this->manager->getRepository(Transfer::class)->count([]));
    }

    public function testAnotherUsersTransferIsForbidden(): void
    {
        $other = $this->createUser('bob@example.test', 'Bob');
        $transfer = $this->createTransfer($this->createAccount('A', 'EUR', $other), $this->createAccount('B', 'EUR', $other));

        $this->client->request('GET', '/transfer/'.$transfer->getId().'/edit');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/transfer/'.$transfer->getId());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/transfer');
        self::assertSelectorNotExists('.tx-list');
    }

    public function testAnAccountWithTransfersCannotBeDeleted(): void
    {
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');
        $this->createTransfer($courant, $livret);

        $this->client->request('GET', '/account/'.$livret->getId());
        $this->client->submitForm('Supprimer');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'virements');
        self::assertNotNull($this->manager->getRepository(Account::class)->find($livret->getId()));
    }

    public function testTheTransferPageRequiresALogin(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/transfer');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testTheAccountsPageLinksToTheTransfers(): void
    {
        $this->client->request('GET', '/account');

        self::assertSelectorExists('a[href="/transfer"]');
    }
}
