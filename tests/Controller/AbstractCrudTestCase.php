<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\TransactionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AbstractCrudTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->manager = static::getContainer()->get('doctrine')->getManager();

        // Ordre imposé par les clés étrangères.
        foreach ([Transaction::class, Budget::class, Category::class, Account::class] as $class) {
            $this->manager->createQuery(sprintf('DELETE FROM %s e', $class))->execute();
        }
    }

    protected function createAccount(string $name = 'Compte courant', string $currency = 'EUR'): Account
    {
        $account = (new Account())->setName($name)->setCurrency($currency);
        $this->manager->persist($account);
        $this->manager->flush();

        return $account;
    }

    protected function createCategory(string $name = 'Courses', ?string $color = null): Category
    {
        $category = (new Category())->setName($name)->setColor($color);
        $this->manager->persist($category);
        $this->manager->flush();

        return $category;
    }

    protected function createTransaction(Account $account, Category $category, string $label = 'Supermarché'): Transaction
    {
        $transaction = (new Transaction())
            ->setLabel($label)
            ->setAmount('42.50')
            ->setType(TransactionType::Expense)
            ->setDate(new \DateTimeImmutable('2026-11-15'))
            ->setAccount($account)
            ->setCategory($category);
        $this->manager->persist($transaction);
        $this->manager->flush();

        return $transaction;
    }

    protected function createBudget(Category $category, string $month = '2026-11-01', string $amount = '300.00'): Budget
    {
        $budget = (new Budget())
            ->setCategory($category)
            ->setMonth(new \DateTimeImmutable($month))
            ->setAmount($amount);
        $this->manager->persist($budget);
        $this->manager->flush();

        return $budget;
    }
}
