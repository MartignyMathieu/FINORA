<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use App\Service\UndoDeletion;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Annulation d'une suppression : bouton dans le message, restauration, jeton à usage unique, expiration, isolation. */
final class UndoDeletionTest extends AbstractCrudTestCase
{
    private function makeTransaction(string $label = 'Courses', string $amount = '85.40'): Transaction
    {
        $account = $this->manager->getRepository(Account::class)->findOneBy(['owner' => $this->user]) ?? $this->createAccount('Compte courant');
        $category = $this->createCategory('Alimentation'.uniqid());

        $transaction = $this->createDatedTransaction($account, $category, TransactionType::Expense, $amount, '2026-11-18', $label)->setNote('Une note');
        $this->manager->flush();

        return $transaction;
    }

    /** Supprime depuis la page de modification et renvoie le crawler de la page suivante. */
    private function deleteFromEditPage(Transaction $transaction): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->request('GET', '/transaction/'.$transaction->getId().'/edit');
        $this->client->submitForm('Supprimer');
        self::assertResponseRedirects('/transaction');

        return $this->client->followRedirect();
    }

    public function testDeletingATransactionOffersToUndoIt(): void
    {
        $transaction = $this->makeTransaction();

        $crawler = $this->deleteFromEditPage($transaction);

        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
        self::assertSelectorTextContains('.flash-undo', 'La transaction a été supprimée.');
        self::assertSelectorExists('.flash-undo form[action^="/undo/"] button.flash-action');
        self::assertSame('15000', $crawler->filter('.flash-undo')->attr('data-flash-delay-value'));
        self::assertMatchesRegularExpression('#^/undo/[a-f0-9]{16}$#', $crawler->filter('.flash-undo form')->attr('action'));
    }

    public function testUndoingBringsTheTransactionBackWithTheSameContent(): void
    {
        $transaction = $this->makeTransaction('Courses', '85.40');
        $accountId = $transaction->getAccount()->getId();
        $categoryId = $transaction->getCategory()->getId();
        $crawler = $this->deleteFromEditPage($transaction);

        $this->client->submit($crawler->filter('.flash-undo form')->form());

        self::assertResponseRedirects('/transaction');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', '1 transaction restaurée');

        $this->manager->clear();
        $restored = $this->manager->getRepository(Transaction::class)->findAll();
        self::assertCount(1, $restored);
        self::assertSame('Courses', $restored[0]->getLabel());
        self::assertSame('85.40', $restored[0]->getAmount());
        self::assertSame(TransactionType::Expense, $restored[0]->getType());
        self::assertSame('2026-11-18', $restored[0]->getDate()->format('Y-m-d'));
        self::assertSame('Une note', $restored[0]->getNote());
        self::assertSame($accountId, $restored[0]->getAccount()->getId());
        self::assertSame($categoryId, $restored[0]->getCategory()->getId());
    }

    public function testTheUndoLinkWorksOnlyOnce(): void
    {
        $transaction = $this->makeTransaction();
        $crawler = $this->deleteFromEditPage($transaction);
        $form = $crawler->filter('.flash-undo form')->form();

        $this->client->submit($form);
        self::assertSame(1, $this->manager->getRepository(Transaction::class)->count([]));

        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'délai pour annuler');
        self::assertSame(1, $this->manager->getRepository(Transaction::class)->count([]), 'Pas de doublon');
    }

    public function testAForgedOrWrongCsrfTokenDoesNothing(): void
    {
        $transaction = $this->makeTransaction();
        $crawler = $this->deleteFromEditPage($transaction);
        $action = $crawler->filter('.flash-undo form')->attr('action');

        $this->client->request('POST', $action, ['_token' => 'faux']);

        self::assertResponseRedirects('/transaction');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Annulation impossible');
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testAnUnknownTokenWithoutAValidFormIsRefused(): void
    {
        $this->client->request('POST', '/undo/0123456789abcdef');

        self::assertResponseRedirects('/transaction');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Annulation impossible');
    }

    public function testATokenMalformedInTheAddressIsNotRouted(): void
    {
        $this->client->request('POST', '/undo/pas-un-jeton');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingATransferCanBeUndone(): void
    {
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');
        $transfer = $this->createTransfer($courant, $livret, '150.00', '2026-11-10');

        $this->client->request('GET', '/transfer/'.$transfer->getId().'/edit');
        $this->client->submitForm('Supprimer');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-undo', 'virement a été supprimé');
        self::assertSame(0, $this->manager->getRepository(Transfer::class)->count([]));

        $this->client->submit($crawler->filter('.flash-undo form')->form());
        self::assertResponseRedirects('/transfer');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', '1 virement restauré');

        $this->manager->clear();
        $restored = $this->manager->getRepository(Transfer::class)->findAll();
        self::assertCount(1, $restored);
        self::assertSame('150.00', $restored[0]->getAmount());
        self::assertSame($courant->getId(), $restored[0]->getFromAccount()->getId());
        self::assertSame($livret->getId(), $restored[0]->getToAccount()->getId());
    }

    public function testARecurringTransactionKeepsItsLinkToTheRule(): void
    {
        $account = $this->createAccount('Compte courant');
        $rule = (new RecurringTransaction())->setLabel('Loyer')->setAmount('500.00')->setType(TransactionType::Expense)
            ->setAccount($account)->setCategory($this->createCategory('Logement'))->setFrequency(RecurrenceFrequency::Monthly)
            ->setStartDate(new \DateTimeImmutable('2026-10-05'));
        $this->manager->persist($rule);
        $this->manager->flush();
        $transaction = $this->createDatedTransaction($account, $rule->getCategory(), TransactionType::Expense, '500.00', '2026-10-05', 'Loyer');
        $transaction->setRecurringTransaction($rule);
        $this->manager->flush();
        $ruleId = $rule->getId();

        $crawler = $this->deleteFromEditPage($transaction);
        $this->client->submit($crawler->filter('.flash-undo form')->form());

        $this->manager->clear();
        $restored = $this->manager->getRepository(Transaction::class)->findAll();
        self::assertCount(1, $restored);
        self::assertSame($ruleId, $restored[0]->getRecurringTransaction()?->getId());
    }

    // --- Service : expiration, éléments disparus, limites ---

    private function service(string $now = '2026-11-20 10:00:00', ?MockClock $clock = null): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $clock ??= new MockClock($now);

        return [new UndoDeletion($stack, $this->manager, $clock), $clock, $session];
    }

    public function testATokenExpiresAfterTenMinutes(): void
    {
        $transaction = $this->makeTransaction();
        [$undo, $clock] = $this->service();
        $token = $undo->rememberTransactions($this->user, [$transaction]);
        $this->manager->remove($transaction);
        $this->manager->flush();

        $clock->modify('+10 minutes +1 second');

        self::assertNull($undo->restore($this->user, $token));
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testATokenStillWorksJustBeforeItExpires(): void
    {
        $transaction = $this->makeTransaction();
        [$undo, $clock] = $this->service();
        $token = $undo->rememberTransactions($this->user, [$transaction]);
        $this->manager->remove($transaction);
        $this->manager->flush();

        $clock->modify('+9 minutes');

        self::assertSame(['kind' => 'transactions', 'restored' => 1, 'skipped' => 0], $undo->restore($this->user, $token));
    }

    public function testLinesWhoseAccountWasDeletedMeanwhileAreSkipped(): void
    {
        $account = $this->createAccount('Éphémère');
        $category = $this->createCategory('Divers');
        $kept = $this->createDatedTransaction($this->createAccount('Gardé'), $category, TransactionType::Expense, '10.00', '2026-11-01', 'Reste');
        $gone = $this->createDatedTransaction($account, $category, TransactionType::Expense, '20.00', '2026-11-02', 'Disparue');
        [$undo] = $this->service();
        $token = $undo->rememberTransactions($this->user, [$kept, $gone]);
        $this->manager->remove($kept);
        $this->manager->remove($gone);
        $this->manager->flush();
        $this->manager->remove($account);
        $this->manager->flush();

        $result = $undo->restore($this->user, $token);

        self::assertSame(1, $result['restored']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(['Reste'], array_map(static fn (Transaction $t): string => $t->getLabel(), $this->manager->getRepository(Transaction::class)->findAll()));
    }

    public function testAnotherUserCannotRestoreSomeoneElsesDeletion(): void
    {
        $transaction = $this->makeTransaction();
        [$undo] = $this->service();
        $token = $undo->rememberTransactions($this->user, [$transaction]);
        $this->manager->remove($transaction);
        $this->manager->flush();

        self::assertNull($undo->restore($this->createUser('bob@example.test', 'Bob'), $token));
    }

    public function testOnlyTheLastFiveDeletionsAreKeptAndNothingIsRememberedForAnEmptyList(): void
    {
        $transaction = $this->makeTransaction();
        [$undo] = $this->service();

        self::assertNull($undo->rememberTransactions($this->user, []));

        $tokens = [];
        for ($i = 0; $i < 6; ++$i) {
            $tokens[] = $undo->rememberTransactions($this->user, [$transaction]);
        }
        $this->manager->remove($transaction);
        $this->manager->flush();

        self::assertNull($undo->restore($this->user, $tokens[0]), 'La plus ancienne est oubliée');
        self::assertSame(1, $undo->restore($this->user, $tokens[5])['restored']);
    }

    public function testBulkSnapshotsRestoreEveryLineAtOnce(): void
    {
        $first = $this->makeTransaction('Un', '1.00');
        $second = $this->makeTransaction('Deux', '2.00');
        [$undo] = $this->service();
        $token = $undo->rememberTransactions($this->user, [$first, $second]);
        $this->manager->remove($first);
        $this->manager->remove($second);
        $this->manager->flush();

        $result = $undo->restore($this->user, $token);

        self::assertSame(2, $result['restored']);
        self::assertSame(2, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testTheUndoRouteRequiresALogin(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('POST', '/undo/0123456789abcdef');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testNormalFlashMessagesAreUnchanged(): void
    {
        $this->client->request('GET', '/transfer/new');
        $courant = $this->createAccount('Compte courant');
        $livret = $this->createAccount('Livret A');
        $this->client->request('GET', '/transfer/new');
        $this->client->submitForm('Enregistrer', [
            'transfer[fromAccount]' => (string) $courant->getId(),
            'transfer[toAccount]' => (string) $livret->getId(),
            'transfer[amount]' => '10.00',
            'transfer[date]' => '2026-11-10',
        ]);
        $this->client->followRedirect();

        self::assertSelectorExists('.flash-success');
        self::assertSelectorNotExists('.flash-undo');
    }
}
