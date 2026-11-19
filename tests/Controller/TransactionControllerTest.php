<?php

namespace App\Tests\Controller;

use App\Entity\Transaction;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;

final class TransactionControllerTest extends AbstractCrudTestCase
{
    public function testIndex(): void
    {
        $this->createTransaction($this->createAccount(), $this->createCategory(), 'Supermarché');

        $this->client->request('GET', '/transaction');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Supermarché');
        self::assertSelectorTextContains('table', 'Courses');
    }

    public function testNew(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();

        $this->client->request('GET', '/transaction/new');
        $this->client->submitForm('Save', [
            'transaction[label]' => 'Loyer',
            'transaction[amount]' => '750',
            'transaction[type]' => TransactionType::Expense->value,
            'transaction[date]' => '2026-11-01',
            'transaction[account]' => (string) $account->getId(),
            'transaction[category]' => (string) $category->getId(),
            'transaction[recurrence]' => RecurrenceFrequency::Monthly->value,
        ]);

        self::assertResponseRedirects('/transaction');

        $transaction = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Loyer']);
        self::assertNotNull($transaction);
        self::assertSame('750.00', $transaction->getAmount());
        self::assertSame(TransactionType::Expense, $transaction->getType());
        self::assertSame(RecurrenceFrequency::Monthly, $transaction->getRecurrence());
        self::assertSame('2026-11-01', $transaction->getDate()->format('Y-m-d'));
        self::assertSame($account->getId(), $transaction->getAccount()->getId());
    }

    public function testNewWithoutRecurrenceIsOneOff(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();

        $this->client->request('GET', '/transaction/new');
        $this->client->submitForm('Save', [
            'transaction[label]' => 'Café',
            'transaction[amount]' => '2.4',
            'transaction[type]' => TransactionType::Expense->value,
            'transaction[date]' => '2026-11-02',
            'transaction[account]' => (string) $account->getId(),
            'transaction[category]' => (string) $category->getId(),
        ]);

        self::assertResponseRedirects('/transaction');
        $transaction = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Café']);
        self::assertNull($transaction->getRecurrence());
        self::assertNull($transaction->getRecurrenceEndDate());
    }

    public function testNewWithNegativeAmountIsRejected(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();

        $this->client->request('GET', '/transaction/new');
        $this->client->submitForm('Save', [
            'transaction[label]' => 'Erreur',
            'transaction[amount]' => '-5',
            'transaction[type]' => TransactionType::Income->value,
            'transaction[date]' => '2026-11-02',
            'transaction[account]' => (string) $account->getId(),
            'transaction[category]' => (string) $category->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testNewWithEndDateBeforeTransactionDateIsRejected(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();

        $this->client->request('GET', '/transaction/new');
        $this->client->submitForm('Save', [
            'transaction[label]' => 'Abonnement',
            'transaction[amount]' => '10',
            'transaction[type]' => TransactionType::Expense->value,
            'transaction[date]' => '2026-11-10',
            'transaction[account]' => (string) $account->getId(),
            'transaction[category]' => (string) $category->getId(),
            'transaction[recurrence]' => RecurrenceFrequency::Monthly->value,
            'transaction[recurrenceEndDate]' => '2026-10-01',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testShow(): void
    {
        $transaction = $this->createTransaction($this->createAccount(), $this->createCategory(), 'Pharmacie');

        $this->client->request('GET', '/transaction/'.$transaction->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Pharmacie');
        self::assertSelectorTextContains('table', 'expense');
    }

    public function testEdit(): void
    {
        $transaction = $this->createTransaction($this->createAccount(), $this->createCategory());

        $this->client->request('GET', sprintf('/transaction/%d/edit', $transaction->getId()));
        $this->client->submitForm('Update', [
            'transaction[label]' => 'Marché',
            'transaction[type]' => TransactionType::Income->value,
        ]);

        self::assertResponseRedirects('/transaction');
        $this->manager->clear();
        $transaction = $this->manager->find(Transaction::class, $transaction->getId());
        self::assertSame('Marché', $transaction->getLabel());
        self::assertSame(TransactionType::Income, $transaction->getType());
    }

    public function testRemove(): void
    {
        $transaction = $this->createTransaction($this->createAccount(), $this->createCategory());

        $this->client->request('GET', '/transaction/'.$transaction->getId());
        $this->client->submitForm('Delete');

        self::assertResponseRedirects('/transaction');
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }
}
