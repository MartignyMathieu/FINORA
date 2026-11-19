<?php

namespace App\Tests\Unit;

use App\Enum\RecurrenceFrequency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurrenceFrequencyTest extends TestCase
{
    /** @return iterable<string, array{RecurrenceFrequency, string, int, string}> */
    public static function occurrences(): iterable
    {
        // Mensuel : le jour de départ est conservé, ramené en fin de mois si besoin, sans dérive.
        yield 'mensuel, jour courant' => [RecurrenceFrequency::Monthly, '2026-06-05', 3, '2026-09-05'];
        yield 'mensuel, le 31 : février' => [RecurrenceFrequency::Monthly, '2027-01-31', 1, '2027-02-28'];
        yield 'mensuel, le 31 : mars (retour au 31)' => [RecurrenceFrequency::Monthly, '2027-01-31', 2, '2027-03-31'];
        yield 'mensuel, le 31 : avril' => [RecurrenceFrequency::Monthly, '2027-01-31', 3, '2027-04-30'];
        yield 'mensuel, année bissextile' => [RecurrenceFrequency::Monthly, '2028-01-31', 1, '2028-02-29'];
        yield 'mensuel, passage d\'année' => [RecurrenceFrequency::Monthly, '2026-11-15', 2, '2027-01-15'];
        yield 'mensuel, 12 mois' => [RecurrenceFrequency::Monthly, '2026-03-10', 12, '2027-03-10'];
        yield 'annuel' => [RecurrenceFrequency::Yearly, '2026-04-12', 2, '2028-04-12'];
        yield 'annuel, 29 février → 28 février' => [RecurrenceFrequency::Yearly, '2028-02-29', 1, '2029-02-28'];
        yield 'annuel, 29 février → 29 février' => [RecurrenceFrequency::Yearly, '2028-02-29', 4, '2032-02-29'];
        yield 'hebdomadaire' => [RecurrenceFrequency::Weekly, '2026-11-02', 3, '2026-11-23'];
        yield 'quotidien' => [RecurrenceFrequency::Daily, '2026-12-30', 3, '2027-01-02'];
        yield 'index zéro = date de départ' => [RecurrenceFrequency::Monthly, '2026-11-19', 0, '2026-11-19'];
    }

    #[DataProvider('occurrences')]
    public function testOccurrence(RecurrenceFrequency $frequency, string $start, int $index, string $expected): void
    {
        self::assertSame($expected, $frequency->occurrence(new \DateTimeImmutable($start), $index)->format('Y-m-d'));
    }

    public function testOccurrenceKeepsTheTimeOfDay(): void
    {
        $date = RecurrenceFrequency::Monthly->occurrence(new \DateTimeImmutable('2026-01-31 00:00:00'), 1);

        self::assertSame('2026-02-28 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testNegativeIndexIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecurrenceFrequency::Daily->occurrence(new \DateTimeImmutable('2026-01-01'), -1);
    }

    public function testMonthlyEquivalents(): void
    {
        self::assertSame(1.0, RecurrenceFrequency::Monthly->monthlyFactor());
        self::assertEqualsWithDelta(1 / 12, RecurrenceFrequency::Yearly->monthlyFactor(), 1e-9);
        self::assertEqualsWithDelta(4.348, RecurrenceFrequency::Weekly->monthlyFactor(), 0.001);
        self::assertEqualsWithDelta(30.4375, RecurrenceFrequency::Daily->monthlyFactor(), 1e-9);
    }

    public function testEveryFrequencyHasAFrenchLabel(): void
    {
        foreach (RecurrenceFrequency::cases() as $case) {
            self::assertNotSame('', $case->label());
        }
    }
}
