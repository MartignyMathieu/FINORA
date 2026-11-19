<?php

namespace App\Tests\Controller;

use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use App\Service\RecurringTransactionGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

final class RecurringTransactionGeneratorTest extends AbstractCrudTestCase
{
    private RecurringTransactionGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = static::getContainer()->get(RecurringTransactionGenerator::class);
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    /** @return list<string> */
    private function generatedDates(RecurringTransaction $rule): array
    {
        $this->manager->clear();
        $transactions = $this->manager->getRepository(Transaction::class)->findBy(['recurringTransaction' => $rule->getId()], ['date' => 'ASC']);

        return array_map(static fn (Transaction $t): string => $t->getDate()->format('Y-m-d'), $transactions);
    }

    public function testGeneratesEveryDueOccurrenceWithTheRuleData(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory('Logement');
        $rule = $this->createRecurring($account, $category, '2026-06-05', RecurrenceFrequency::Monthly, '500.00', TransactionType::Expense, 'Loyer');
        $rule->setNote('Virement permanent');
        $this->manager->flush();

        $created = $this->generator->generateFor($rule, $this->day('2026-11-19'));

        self::assertSame(6, $created);
        self::assertSame(['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05', '2026-11-05'], $this->generatedDates($rule));

        $transaction = $this->manager->getRepository(Transaction::class)->findOneBy(['date' => $this->day('2026-11-05')]);
        self::assertSame('Loyer', $transaction->getLabel());
        self::assertSame('500.00', $transaction->getAmount());
        self::assertSame(TransactionType::Expense, $transaction->getType());
        self::assertSame('Virement permanent', $transaction->getNote());
        self::assertSame($account->getId(), $transaction->getAccount()->getId());
        self::assertSame($category->getId(), $transaction->getCategory()->getId());
        self::assertSame($rule->getId(), $transaction->getRecurringTransaction()->getId());
        self::assertSame($this->user->getId(), $transaction->getOwner()->getId());

        $this->manager->clear();
        self::assertSame('2026-11-05', $this->manager->find(RecurringTransaction::class, $rule->getId())->getLastGeneratedDate()->format('Y-m-d'));
    }

    public function testRunningTwiceNeverCreatesDuplicates(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory());

        $first = $this->generator->generateFor($rule, $this->day('2026-11-19'));
        $second = $this->generator->generateFor($rule, $this->day('2026-11-19'));

        self::assertSame(6, $first);
        self::assertSame(0, $second);
        self::assertSame(6, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testMissedOccurrencesAreCaughtUpLater(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory());

        self::assertSame(3, $this->generator->generateFor($rule, $this->day('2026-08-20')));
        self::assertSame(3, $this->generator->generateFor($rule, $this->day('2026-11-19')));

        self::assertCount(6, $this->generatedDates($rule));
    }

    public function testAnOccurrenceFallingOnTodayIsGenerated(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory(), '2026-11-19');

        self::assertSame(1, $this->generator->generateFor($rule, $this->day('2026-11-19')));
    }

    public function testNothingIsGeneratedBeforeTheStartDate(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory(), '2026-12-01');

        self::assertSame(0, $this->generator->generateFor($rule, $this->day('2026-11-19')));
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testGenerationStopsAtTheEndDate(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory(), '2026-06-05', end: '2026-08-31');

        self::assertSame(3, $this->generator->generateFor($rule, $this->day('2027-06-01')));
        self::assertSame(['2026-06-05', '2026-07-05', '2026-08-05'], $this->generatedDates($rule));
    }

    public function testAPausedRuleGeneratesNothingUntilItIsResumed(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory(), '2026-06-05', active: false);

        self::assertSame(0, $this->generator->generateFor($rule, $this->day('2026-11-19')));

        $rule->setActive(true);
        $this->manager->flush();
        self::assertSame(6, $this->generator->generateFor($rule, $this->day('2026-11-19')), 'À la reprise, les échéances manquées sont rattrapées.');
    }

    public function testGenerateDueCoversEveryUsersActiveRules(): void
    {
        $bob = $this->createUser('bob@example.test', 'Bob');
        $this->createRecurring($this->createAccount(), $this->createCategory(), '2026-09-01', label: 'Loyer d\'Alice');
        $this->createRecurring($this->createAccount('Compte de Bob', 'EUR', $bob), $this->createCategory('Cat. Bob', null, $bob), '2026-10-01', label: 'Loyer de Bob');
        $this->createRecurring($this->createAccount('Autre'), $this->createCategory('Autre'), '2026-01-01', label: 'En pause', active: false);

        $created = $this->generator->generateDue($this->day('2026-11-19'));

        self::assertSame(3 + 2, $created);
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count(['label' => 'En pause']));
    }

    public function testGenerationWithoutExplicitDateUsesTheClock(): void
    {
        $today = new \DateTimeImmutable('today');
        $rule = $this->createRecurring(
            $this->createAccount(),
            $this->createCategory(),
            $today->modify('-2 days')->format('Y-m-d'),
            RecurrenceFrequency::Daily,
        );

        self::assertSame(3, $this->generator->generateFor($rule));
    }

    public function testADailyRuleIsCappedPerRunAndContinuesNextTime(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory(), '2023-11-01', RecurrenceFrequency::Daily, '1.00');

        $first = $this->generator->generateFor($rule, $this->day('2026-11-19'));
        $second = $this->generator->generateFor($rule, $this->day('2026-11-19'));
        $third = $this->generator->generateFor($rule, $this->day('2026-11-19'));

        self::assertSame(1000, $first);
        self::assertGreaterThan(0, $second);
        self::assertSame(0, $third);
        self::assertSame(1115, $first + $second); // 2023-11-01 → 2026-11-19 inclus
    }

    public function testTheDatabaseRefusesTheSameOccurrenceTwice(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $rule = $this->createRecurring($account, $category);
        $this->generator->generateFor($rule, $this->day('2026-06-30'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->manager->persist(
            (new Transaction())->setLabel('Doublon')->setAmount('1.00')->setType(TransactionType::Expense)
                ->setDate($this->day('2026-06-05'))->setAccount($account)->setCategory($category)
                ->setRecurringTransaction($this->manager->find(RecurringTransaction::class, $rule->getId()))
        );
        $this->manager->flush();
    }

    public function testManualTransactionsOnTheSameDateAreNotAffectedByTheConstraint(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '5.00', '2026-06-05', 'Manuel 1');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '6.00', '2026-06-05', 'Manuel 2');

        self::assertSame(2, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testDeletingARuleKeepsItsGeneratedTransactions(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory());
        $this->generator->generateFor($rule, $this->day('2026-11-19'));

        $this->manager->remove($this->manager->find(RecurringTransaction::class, $rule->getId()));
        $this->manager->flush();
        $this->manager->clear();

        $transactions = $this->manager->getRepository(Transaction::class)->findAll();
        self::assertCount(6, $transactions);
        foreach ($transactions as $transaction) {
            self::assertNull($transaction->getRecurringTransaction());
        }
    }

    public function testGeneratedTransactionsCountInBalancesAndTotals(): void
    {
        $account = $this->createAccount();
        $account->setInitialBalance('1000.00');
        $this->manager->flush();
        $rule = $this->createRecurring($account, $this->createCategory(), '2026-09-05', amount: '500.00');
        $this->generator->generateFor($rule, $this->day('2026-11-19'));

        $this->client->request('GET', '/account');
        self::assertSelectorTextContains('.list', "\u{2212}500,00");
        $this->client->request('GET', '/?month=2026-11');
        self::assertSelectorTextContains('.kpis', "500,00");
    }

    public function testTheCommandGeneratesTransactionsForTheGivenDate(): void
    {
        $rule = $this->createRecurring($this->createAccount(), $this->createCategory());
        $tester = new CommandTester((new Application(static::$kernel))->find('app:recurring:generate'));

        $status = $tester->execute(['--date' => '2026-11-19']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('6 transaction(s) générée(s)', $tester->getDisplay());
        self::assertCount(6, $this->generatedDates($rule));

        $tester->execute(['--date' => '2026-11-19']);
        self::assertStringContainsString('0 transaction(s) générée(s)', $tester->getDisplay());
    }

    public function testTheCommandRejectsAnInvalidDate(): void
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:recurring:generate'));

        self::assertSame(Command::INVALID, $tester->execute(['--date' => '19/11/2026']));
        self::assertSame(Command::INVALID, $tester->execute(['--date' => '2026-02-31']));
        self::assertStringContainsString('AAAA-MM-JJ', $tester->getDisplay());
    }

    public function testTheCommandIsScheduledEveryNight(): void
    {
        $attributes = (new \ReflectionClass(\App\Command\GenerateRecurringTransactionsCommand::class))->getAttributes(AsCronTask::class);

        self::assertCount(1, $attributes);
        self::assertSame('0 3 * * *', $attributes[0]->newInstance()->expression);
    }
}
