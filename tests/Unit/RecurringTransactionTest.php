<?php

namespace App\Tests\Unit;

use App\Entity\RecurringTransaction;
use App\Enum\RecurrenceFrequency;
use PHPUnit\Framework\TestCase;

final class RecurringTransactionTest extends TestCase
{
    private function rule(string $start = '2026-06-05', ?string $end = null, ?string $last = null, RecurrenceFrequency $frequency = RecurrenceFrequency::Monthly): RecurringTransaction
    {
        return (new RecurringTransaction())
            ->setFrequency($frequency)
            ->setStartDate(new \DateTimeImmutable($start))
            ->setEndDate($end ? new \DateTimeImmutable($end) : null)
            ->setLastGeneratedDate($last ? new \DateTimeImmutable($last) : null)
            ->setAmount('100.00');
    }

    /** @param list<\DateTimeImmutable> $dates @return list<string> */
    private static function format(array $dates): array
    {
        return array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates);
    }

    public function testDueDatesFromTheStartUpToAndIncludingToday(): void
    {
        $dates = $this->rule('2026-06-05')->getDueDates(new \DateTimeImmutable('2026-09-05'));

        self::assertSame(['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05'], self::format($dates));
    }

    public function testNothingIsDueBeforeTheFirstOccurrence(): void
    {
        self::assertSame([], $this->rule('2026-06-05')->getDueDates(new \DateTimeImmutable('2026-06-04')));
    }

    public function testAlreadyGeneratedOccurrencesAreSkipped(): void
    {
        $dates = $this->rule('2026-06-05', null, '2026-07-05')->getDueDates(new \DateTimeImmutable('2026-09-20'));

        self::assertSame(['2026-08-05', '2026-09-05'], self::format($dates));
    }

    public function testTheEndDateIsInclusiveAndFinal(): void
    {
        $dates = $this->rule('2026-06-05', '2026-08-05')->getDueDates(new \DateTimeImmutable('2027-01-01'));

        self::assertSame(['2026-06-05', '2026-07-05', '2026-08-05'], self::format($dates));
    }

    public function testAnEndDateBetweenOccurrencesStopsBeforeTheNextOne(): void
    {
        $dates = $this->rule('2026-06-05', '2026-08-04')->getDueDates(new \DateTimeImmutable('2027-01-01'));

        self::assertSame(['2026-06-05', '2026-07-05'], self::format($dates));
    }

    public function testTheLimitCapsTheNumberOfDates(): void
    {
        $dates = $this->rule('2026-01-01', null, null, RecurrenceFrequency::Daily)->getDueDates(new \DateTimeImmutable('2026-12-31'), 5);

        self::assertSame(['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05'], self::format($dates));
    }

    public function testNextDueDate(): void
    {
        self::assertSame('2026-06-05', $this->rule('2026-06-05')->getNextDueDate()?->format('Y-m-d'));
        self::assertSame('2026-08-05', $this->rule('2026-06-05', null, '2026-07-05')->getNextDueDate()?->format('Y-m-d'));
        // Dernière génération entre deux échéances : la suivante est la prochaine échéance de la règle.
        self::assertSame('2026-08-05', $this->rule('2026-06-05', null, '2026-07-20')->getNextDueDate()?->format('Y-m-d'));
    }

    public function testARuleWithNoOccurrenceLeftIsEnded(): void
    {
        $rule = $this->rule('2026-06-05', '2026-08-05', '2026-08-05');

        self::assertNull($rule->getNextDueDate());
        self::assertTrue($rule->isEnded());
        self::assertFalse($this->rule('2026-06-05', '2026-08-05', '2026-07-05')->isEnded());
        self::assertFalse($this->rule('2026-06-05')->isEnded(), 'Une règle sans fin n\'est jamais terminée.');
    }

    public function testMonthlyAmountNormalisesEveryFrequency(): void
    {
        self::assertEqualsWithDelta(100.0, $this->rule()->getMonthlyAmount(), 0.001);
        self::assertEqualsWithDelta(100 / 12, $this->rule(frequency: RecurrenceFrequency::Yearly)->getMonthlyAmount(), 0.001);
        self::assertEqualsWithDelta(434.81, $this->rule(frequency: RecurrenceFrequency::Weekly)->getMonthlyAmount(), 0.01);
    }
}
