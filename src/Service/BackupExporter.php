<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sauvegarde complète des données d'un utilisateur dans un document JSON qu'il peut garder et restaurer plus tard
 * (voir BackupRestorer) : comptes, catégories, transactions, récurrences, budgets, objectifs (avec leur historique) et virements.
 *
 * Le document ne contient ni adresse e-mail ni mot de passe : seulement les données financières. Les éléments se référencent entre eux
 * par des numéros propres au fichier (pas les identifiants de la base).
 */
final class BackupExporter
{
    public const FORMAT = 'finora-backup';
    public const VERSION = 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $accounts = $this->em->getRepository(Account::class)->findBy(['owner' => $user], ['id' => 'ASC']);
        $categories = $this->em->getRepository(Category::class)->findBy(['owner' => $user], ['id' => 'ASC']);
        $rules = $this->em->createQuery('SELECT r, a FROM '.RecurringTransaction::class.' r JOIN r.account a WHERE a.owner = :user ORDER BY r.id ASC')
            ->setParameter('user', $user)->getResult();

        $transactions = [];
        $query = $this->em->createQuery('SELECT t, a, c, r FROM '.Transaction::class.' t JOIN t.account a JOIN t.category c LEFT JOIN t.recurringTransaction r WHERE a.owner = :user ORDER BY t.date ASC, t.id ASC')
            ->setParameter('user', $user);
        foreach ($query->toIterable() as $transaction) {
            /** @var Transaction $transaction */
            $transactions[] = [
                'account' => $transaction->getAccount()->getId(),
                'category' => $transaction->getCategory()->getId(),
                'type' => $transaction->getType()->value,
                'date' => $transaction->getDate()->format('Y-m-d'),
                'label' => $transaction->getLabel(),
                'amount' => $transaction->getAmount(),
                'note' => $transaction->getNote(),
                'recurring' => $transaction->getRecurringTransaction()?->getId(),
            ];
        }

        $budgets = $this->em->createQuery('SELECT b, c FROM '.Budget::class.' b JOIN b.category c WHERE c.owner = :user ORDER BY b.month ASC, b.id ASC')
            ->setParameter('user', $user)->getResult();
        $goals = $this->em->getRepository(Goal::class)->findBy(['owner' => $user], ['id' => 'ASC']);
        $transfers = $this->em->createQuery('SELECT t, f, a FROM '.Transfer::class.' t JOIN t.fromAccount f JOIN t.toAccount a WHERE f.owner = :user ORDER BY t.date ASC, t.id ASC')
            ->setParameter('user', $user)->getResult();

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exportedAt' => $this->clock->now()->format(\DATE_ATOM),
            'user' => ['displayName' => $user->getDisplayName()],
            'accounts' => array_map(static fn (Account $a): array => [
                'id' => $a->getId(),
                'name' => $a->getName(),
                'type' => $a->getType()->value,
                'currency' => $a->getCurrency(),
                'initialBalance' => $a->getInitialBalance(),
            ], $accounts),
            'categories' => array_map(static fn (Category $c): array => [
                'id' => $c->getId(),
                'name' => $c->getName(),
                'icon' => $c->getIcon()?->value,
                'color' => $c->getColor(),
            ], $categories),
            'transactions' => $transactions,
            'recurring' => array_map(static fn (RecurringTransaction $r): array => [
                'id' => $r->getId(),
                'label' => $r->getLabel(),
                'amount' => $r->getAmount(),
                'type' => $r->getType()->value,
                'note' => $r->getNote(),
                'account' => $r->getAccount()->getId(),
                'category' => $r->getCategory()->getId(),
                'frequency' => $r->getFrequency()->value,
                'startDate' => $r->getStartDate()->format('Y-m-d'),
                'endDate' => $r->getEndDate()?->format('Y-m-d'),
                'lastGeneratedDate' => $r->getLastGeneratedDate()?->format('Y-m-d'),
                'active' => $r->isActive(),
            ], $rules),
            'budgets' => array_map(static fn (Budget $b): array => [
                'category' => $b->getCategory()->getId(),
                'month' => $b->getMonth()->format('Y-m-d'),
                'amount' => $b->getAmount(),
                'currency' => $b->getCurrency(),
            ], $budgets),
            'goals' => array_map(static fn (Goal $g): array => [
                'name' => $g->getName(),
                'targetAmount' => $g->getTargetAmount(),
                'savedAmount' => $g->getSavedAmount(),
                'currency' => $g->getCurrency(),
                'targetDate' => $g->getTargetDate()?->format('Y-m-d'),
                'icon' => $g->getIcon()?->value,
                'contributions' => array_map(static fn ($c): array => [
                    'amount' => $c->getAmount(),
                    'date' => $c->getDate()->format('Y-m-d'),
                    'note' => $c->getNote(),
                ], array_reverse($g->getContributions()->toArray())),
            ], $goals),
            'transfers' => array_map(static fn (Transfer $t): array => [
                'from' => $t->getFromAccount()->getId(),
                'to' => $t->getToAccount()->getId(),
                'amount' => $t->getAmount(),
                'date' => $t->getDate()->format('Y-m-d'),
                'note' => $t->getNote(),
            ], $transfers),
        ];
    }

    /** Document prêt à enregistrer : JSON lisible, accents conservés. */
    public function toJson(User $user): string
    {
        return json_encode($this->export($user), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }
}
