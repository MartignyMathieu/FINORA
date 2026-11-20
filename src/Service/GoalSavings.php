<?php

namespace App\Service;

use App\Entity\Goal;
use App\Entity\GoalContribution;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Versements et retraits sur un objectif : l'historique et le montant épargné évoluent ensemble, dans la même transaction.
 */
final class GoalSavings
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Enregistre un versement (montant positif) ou un retrait (négatif).
     *
     * @throws \DomainException si un retrait dépasse l'épargne de l'objectif
     */
    public function record(Goal $goal, float $signedAmount, \DateTimeImmutable $date, ?string $note = null): GoalContribution
    {
        $saved = round((float) $goal->getSavedAmount() + $signedAmount, 2);
        if ($saved < 0) {
            throw new \DomainException('Le retrait dépasse le montant épargné sur cet objectif.');
        }

        $contribution = (new GoalContribution())
            ->setGoal($goal)
            ->setAmount(number_format($signedAmount, 2, '.', ''))
            ->setDate($date)
            ->setNote('' === trim((string) $note) ? null : trim((string) $note));

        $goal->setSavedAmount(number_format($saved, 2, '.', ''));
        $this->em->persist($contribution);
        $this->em->flush();

        return $contribution;
    }

    /** Annule une ligne de l'historique : l'épargne de l'objectif revient à ce qu'elle était (jamais en dessous de 0). */
    public function cancel(GoalContribution $contribution): void
    {
        $goal = $contribution->getGoal();
        $saved = max(0.0, round((float) $goal->getSavedAmount() - (float) $contribution->getAmount(), 2));

        $goal->setSavedAmount(number_format($saved, 2, '.', ''));
        $this->em->remove($contribution);
        $this->em->flush();
    }
}
