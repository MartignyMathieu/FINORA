<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
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
        foreach ([Transaction::class, Budget::class, Goal::class, Category::class, Account::class] as $class) {
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

    protected function createGoal(string $name = 'Vacances', string $target = '2000.00', string $saved = '500.00'): Goal
    {
        $goal = (new Goal())
            ->setName($name)
            ->setTargetAmount($target)
            ->setSavedAmount($saved)
            ->setTargetDate(new \DateTimeImmutable('2027-08-15'));
        $this->manager->persist($goal);
        $this->manager->flush();

        return $goal;
    }

    /** Crée une transaction à la date donnée (par défaut le 15 novembre 2026). */
    protected function createDatedTransaction(
        Account $account,
        Category $category,
        TransactionType $type,
        string $amount,
        string $date = '2026-11-15',
        string $label = 'Opération',
    ): Transaction {
        $transaction = (new Transaction())
            ->setLabel($label)
            ->setAmount($amount)
            ->setType($type)
            ->setDate(new \DateTimeImmutable($date))
            ->setAccount($account)
            ->setCategory($category);
        $this->manager->persist($transaction);
        $this->manager->flush();

        return $transaction;
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
