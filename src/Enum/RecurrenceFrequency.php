<?php

namespace App\Enum;

enum RecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Quotidienne',
            self::Weekly => 'Hebdomadaire',
            self::Monthly => 'Mensuelle',
            self::Yearly => 'Annuelle',
        };
    }

    /** Coefficient pour ramener un montant à un équivalent mensuel. */
    public function monthlyFactor(): float
    {
        return match ($this) {
            self::Daily => 365.25 / 12,
            self::Weekly => 52.1775 / 12,
            self::Monthly => 1.0,
            self::Yearly => 1 / 12,
        };
    }

    /**
     * Date de la n-ième occurrence (0 = la date de départ).
     *
     * Les occurrences sont calculées depuis la date de départ, jamais depuis la précédente, pour ne pas
     * dériver : une règle « le 31 » tombe le 28 en février puis revient au 31 en mars.
     */
    public function occurrence(\DateTimeImmutable $start, int $index): \DateTimeImmutable
    {
        if ($index < 0) {
            throw new \InvalidArgumentException('L\'index d\'une occurrence ne peut pas être négatif.');
        }

        return match ($this) {
            self::Daily => $start->modify(sprintf('+%d days', $index)),
            self::Weekly => $start->modify(sprintf('+%d weeks', $index)),
            self::Monthly => $this->addMonths($start, $index),
            self::Yearly => $this->addMonths($start, $index * 12),
        };
    }

    /** Ajoute des mois en ramenant le jour au dernier jour du mois d'arrivée si nécessaire. */
    private function addMonths(\DateTimeImmutable $start, int $months): \DateTimeImmutable
    {
        $day = (int) $start->format('j');
        $firstOfTarget = $start->modify('first day of this month')->modify(sprintf('+%d months', $months));
        $lastDay = (int) $firstOfTarget->format('t');

        return $firstOfTarget->setDate(
            (int) $firstOfTarget->format('Y'),
            (int) $firstOfTarget->format('n'),
            min($day, $lastDay),
        );
    }
}
