<?php

namespace App\Service;

use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Repository\RecurringTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Transforme les échéances des règles récurrentes en vraies transactions.
 *
 * Idempotent : une échéance déjà générée n'est jamais recréée (dernière date générée mémorisée sur la
 * règle, et contrainte d'unicité en base). Rattrape les échéances manquées si le service n'a pas tourné.
 */
final class RecurringTransactionGenerator
{
    /** Garde-fou : une règle ne génère pas plus de transactions par exécution (la suite viendra à la suivante). */
    private const MAX_PER_RUN = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecurringTransactionRepository $repository,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Génère les échéances dues de toutes les règles actives, tous utilisateurs confondus.
     *
     * @return int Nombre de transactions créées
     */
    public function generateDue(?\DateTimeImmutable $today = null): int
    {
        $created = 0;
        foreach ($this->repository->findActive() as $rule) {
            $created += $this->generateFor($rule, $today);
        }

        return $created;
    }

    /**
     * Génère les échéances dues d'une règle (jusqu'à $today inclus, aujourd'hui par défaut).
     * La règle doit déjà être enregistrée.
     *
     * @return int Nombre de transactions créées
     */
    public function generateFor(RecurringTransaction $rule, ?\DateTimeImmutable $today = null): int
    {
        if (!$rule->isActive() || null === $rule->getId()) {
            return 0;
        }

        $today = ($today ?? $this->clock->now())->setTime(0, 0);

        // Un seul processus à la fois par règle (tâche planifiée, création de la règle, commande manuelle…).
        $lock = $this->lockFactory->createLock('recurring-transaction-'.$rule->getId(), 120);
        $lock->acquire(true);

        try {
            // L'état a pu changer pendant l'attente du verrou.
            $this->em->refresh($rule);
            if (!$rule->isActive()) {
                return 0;
            }

            $dates = $rule->getDueDates($today, self::MAX_PER_RUN);
            foreach ($dates as $date) {
                $this->em->persist(
                    (new Transaction())
                        ->setLabel((string) $rule->getLabel())
                        ->setAmount((string) $rule->getAmount())
                        ->setType($rule->getType())
                        ->setNote($rule->getNote())
                        ->setAccount($rule->getAccount())
                        ->setCategory($rule->getCategory())
                        ->setDate($date)
                        ->setRecurringTransaction($rule)
                );
            }

            if ([] !== $dates) {
                $rule->setLastGeneratedDate($dates[array_key_last($dates)]);
                $this->em->flush();
            }

            return \count($dates);
        } finally {
            $lock->release();
        }
    }
}
