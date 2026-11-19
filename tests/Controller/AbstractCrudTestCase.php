<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests fonctionnels : base vidée avant chaque test, et un utilisateur connecté
 * ($this->user) propriétaire par défaut des données créées par les helpers.
 */
abstract class AbstractCrudTestCase extends WebTestCase
{
    protected const PASSWORD = 'mot-de-passe-de-test';

    protected KernelBrowser $client;
    protected EntityManagerInterface $manager;
    protected User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->manager = static::getContainer()->get('doctrine')->getManager();

        // Ordre imposé par les clés étrangères.
        foreach ([Transaction::class, RecurringTransaction::class, Budget::class, Goal::class, Category::class, Account::class, User::class] as $class) {
            $this->manager->createQuery(sprintf('DELETE FROM %s e', $class))->execute();
        }

        // Le limiteur de tentatives de connexion est persistant : on le remet à zéro.
        static::getContainer()->get('cache.rate_limiter')->clear();

        $this->user = $this->createUser('alice@example.test', 'Alice');
        $this->client->loginUser($this->user);
    }

    protected function createUser(string $email, string $displayName = 'Utilisateur'): User
    {
        $user = (new User())->setEmail($email)->setDisplayName($displayName);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->manager->persist($user);
        $this->manager->flush();

        return $user;
    }

    protected function createAccount(string $name = 'Compte courant', string $currency = 'EUR', ?User $owner = null): Account
    {
        $account = (new Account())->setOwner($owner ?? $this->user)->setName($name)->setCurrency($currency);
        $this->manager->persist($account);
        $this->manager->flush();

        return $account;
    }

    protected function createCategory(string $name = 'Courses', ?string $color = null, ?User $owner = null): Category
    {
        $category = (new Category())->setOwner($owner ?? $this->user)->setName($name)->setColor($color);
        $this->manager->persist($category);
        $this->manager->flush();

        return $category;
    }

    protected function createGoal(string $name = 'Vacances', string $target = '2000.00', string $saved = '500.00', ?User $owner = null): Goal
    {
        $goal = (new Goal())
            ->setOwner($owner ?? $this->user)
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
        return $this->createDatedTransaction($account, $category, TransactionType::Expense, '42.50', '2026-11-15', $label);
    }

    /** Crée une règle récurrente (aucune transaction n'est générée : voir RecurringTransactionGenerator). */
    protected function createRecurring(
        Account $account,
        Category $category,
        string $start = '2026-06-05',
        RecurrenceFrequency $frequency = RecurrenceFrequency::Monthly,
        string $amount = '500.00',
        TransactionType $type = TransactionType::Expense,
        string $label = 'Loyer',
        ?string $end = null,
        bool $active = true,
        ?string $lastGenerated = null,
    ): RecurringTransaction {
        $rule = (new RecurringTransaction())
            ->setLabel($label)
            ->setAmount($amount)
            ->setType($type)
            ->setAccount($account)
            ->setCategory($category)
            ->setFrequency($frequency)
            ->setStartDate(new \DateTimeImmutable($start))
            ->setEndDate($end ? new \DateTimeImmutable($end) : null)
            ->setLastGeneratedDate($lastGenerated ? new \DateTimeImmutable($lastGenerated) : null)
            ->setActive($active);
        $this->manager->persist($rule);
        $this->manager->flush();

        return $rule;
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
