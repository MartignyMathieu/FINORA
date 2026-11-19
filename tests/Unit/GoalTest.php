<?php

namespace App\Tests\Unit;

use App\Entity\Goal;
use PHPUnit\Framework\TestCase;

/** Calculs affichés au dos des cartes d'objectifs : reste à épargner, mois restants, effort mensuel. */
final class GoalTest extends TestCase
{
    private function goal(string $target, string $saved, ?string $deadline = null): Goal
    {
        return (new Goal())
            ->setName('Test')
            ->setTargetAmount($target)
            ->setSavedAmount($saved)
            ->setTargetDate($deadline ? new \DateTimeImmutable($deadline) : null);
    }

    private function today(string $date = '2026-11-19'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    public function testRemainingAmountIsNeverNegative(): void
    {
        self::assertSame(800.0, $this->goal('1000.00', '200.00')->getRemaining());
        self::assertSame(0.0, $this->goal('100.00', '100.00')->getRemaining());
        self::assertSame(0.0, $this->goal('100.00', '250.00')->getRemaining(), 'Dépassé : rien à épargner');
    }

    public function testReached(): void
    {
        self::assertTrue($this->goal('100.00', '100.00')->isReached());
        self::assertTrue($this->goal('100.00', '250.00')->isReached());
        self::assertFalse($this->goal('100.00', '99.99')->isReached());
        self::assertFalse($this->goal('100.00', '0.00')->isReached());
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function monthsLeft(): iterable
    {
        yield 'ce mois-ci' => ['2026-11-30', 0];
        yield 'le mois prochain' => ['2026-12-01', 1];
        yield 'dans 9 mois (changement d\'année)' => ['2027-08-15', 9];
        yield 'dans 12 mois' => ['2027-11-01', 12];
        yield 'mois dépassé' => ['2026-10-31', -1];
        yield 'plus d\'un an de retard' => ['2025-05-10', -18];
        yield 'sans échéance' => [null, null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('monthsLeft')]
    public function testMonthsLeftCountsCalendarMonthsNotDays(?string $deadline, ?int $expected): void
    {
        self::assertSame($expected, $this->goal('1000.00', '0.00', $deadline)->getMonthsLeft($this->today()));
    }

    public function testMonthsLeftIgnoresTheDayOfTheMonth(): void
    {
        // Le 31 décembre est « dans 1 mois » le 1er comme le 30 novembre.
        $goal = $this->goal('1000.00', '0.00', '2026-12-31');

        self::assertSame(1, $goal->getMonthsLeft($this->today('2026-11-01')));
        self::assertSame(1, $goal->getMonthsLeft($this->today('2026-11-30')));
    }

    public function testRequiredMonthlyEffortSpreadsTheRemainingAmountOverTheRemainingMonths(): void
    {
        // 700 € restants, échéance dans 7 mois : 100 € par mois.
        self::assertEqualsWithDelta(100.0, $this->goal('1000.00', '300.00', '2027-06-01')->getRequiredMonthly($this->today()), 0.001);
    }

    public function testRequiredMonthlyEffortIsTheWholeRemainingAmountWhenDueThisMonth(): void
    {
        self::assertEqualsWithDelta(400.0, $this->goal('1000.00', '600.00', '2026-11-30')->getRequiredMonthly($this->today()), 0.001);
    }

    public function testRequiredMonthlyEffortIsNullWhenThereIsNothingToComputeOrNoWayToMeetIt(): void
    {
        self::assertNull($this->goal('1000.00', '0.00')->getRequiredMonthly($this->today()), 'Sans échéance');
        self::assertNull($this->goal('1000.00', '1000.00', '2027-06-01')->getRequiredMonthly($this->today()), 'Objectif atteint');
        self::assertNull($this->goal('1000.00', '300.00', '2026-10-01')->getRequiredMonthly($this->today()), 'Échéance passée');
    }

    public function testOverdueMeansPastDeadlineAndNotReached(): void
    {
        self::assertTrue($this->goal('1000.00', '300.00', '2026-10-01')->isOverdue($this->today()));
        self::assertFalse($this->goal('1000.00', '1000.00', '2026-10-01')->isOverdue($this->today()), 'Atteint à temps ou en retard : pas « en retard »');
        self::assertFalse($this->goal('1000.00', '300.00', '2026-11-30')->isOverdue($this->today()), 'Ce mois-ci');
        self::assertFalse($this->goal('1000.00', '300.00')->isOverdue($this->today()), 'Sans échéance');
    }

    public function testProgressIsCappedAtOneHundredPercentAndHandlesEmptyGoals(): void
    {
        self::assertSame(50, $this->goal('200.00', '100.00')->getProgress());
        self::assertSame(100, $this->goal('100.00', '250.00')->getProgress());
        self::assertSame(0, $this->goal('100.00', '0.00')->getProgress());
    }
}
