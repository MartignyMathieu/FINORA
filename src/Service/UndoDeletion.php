<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\TransactionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Annulation d'une suppression de transactions ou de virements.
 *
 * Juste avant de supprimer, on garde dans la session de l'utilisateur une copie des données (pas des objets). Un jeton à usage unique,
 * valable dix minutes, permet de les recréer : les éléments reviennent avec un nouvel identifiant, mais le même contenu. Si le compte ou la
 * catégorie d'une ligne a disparu entre-temps, cette ligne n'est pas restaurée et le résultat l'indique.
 *
 * @phpstan-type Snapshot array{kind: string, user: int, expires: int, items: list<array<string, mixed>>}
 */
final class UndoDeletion
{
    public const KIND_TRANSACTIONS = 'transactions';
    public const KIND_TRANSFER = 'transfer';

    private const SESSION_KEY = 'undo.deletions';
    private const TTL = 600;
    private const MAX_ENTRIES = 5;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * À appeler avant la suppression : garde une copie des transactions et renvoie le jeton d'annulation (null sans session).
     *
     * @param list<Transaction> $transactions
     */
    public function rememberTransactions(User $user, array $transactions): ?string
    {
        $items = array_map(static fn (Transaction $t): array => [
            'account' => $t->getAccount()?->getId(),
            'category' => $t->getCategory()?->getId(),
            'type' => $t->getType()?->value,
            'date' => $t->getDate()?->format('Y-m-d'),
            'label' => $t->getLabel(),
            'amount' => $t->getAmount(),
            'note' => $t->getNote(),
            'rule' => $t->getRecurringTransaction()?->getId(),
        ], $transactions);

        return $this->store($user, self::KIND_TRANSACTIONS, $items);
    }

    public function rememberTransfer(User $user, Transfer $transfer): ?string
    {
        return $this->store($user, self::KIND_TRANSFER, [[
            'from' => $transfer->getFromAccount()?->getId(),
            'to' => $transfer->getToAccount()?->getId(),
            'date' => $transfer->getDate()?->format('Y-m-d'),
            'amount' => $transfer->getAmount(),
            'note' => $transfer->getNote(),
        ]]);
    }

    /**
     * Recrée les éléments supprimés. Le jeton est à usage unique : il disparaît dès qu'il est utilisé.
     *
     * @return array{kind: string, restored: int, skipped: int}|null null si le jeton est inconnu, expiré ou ne vous appartient pas
     */
    public function restore(User $user, string $token): ?array
    {
        $session = $this->requests->getSession();
        $all = $session->get(self::SESSION_KEY, []);
        $entry = $all[$token] ?? null;

        if (!\is_array($entry) || ($entry['user'] ?? null) !== $user->getId() || ($entry['expires'] ?? 0) < $this->clock->now()->getTimestamp()) {
            unset($all[$token]);
            $session->set(self::SESSION_KEY, $all);

            return null;
        }

        unset($all[$token]);
        $session->set(self::SESSION_KEY, $all);

        $result = self::KIND_TRANSFER === $entry['kind'] ? $this->restoreTransfers($user, $entry['items']) : $this->restoreTransactions($user, $entry['items']);

        return ['kind' => $entry['kind']] + $result;
    }

    /** @param list<array<string, mixed>> $items */
    private function store(User $user, string $kind, array $items): ?string
    {
        if ([] === $items) {
            return null;
        }

        $session = $this->requests->getSession();
        $all = $session->get(self::SESSION_KEY, []);
        $now = $this->clock->now()->getTimestamp();
        // Entrées expirées retirées, et les plus anciennes écartées au-delà de la limite.
        $all = array_filter($all, static fn (mixed $entry): bool => \is_array($entry) && ($entry['expires'] ?? 0) >= $now);
        $all = \array_slice($all, -(self::MAX_ENTRIES - 1), null, true);

        $token = bin2hex(random_bytes(8));
        $all[$token] = ['kind' => $kind, 'user' => $user->getId(), 'expires' => $now + self::TTL, 'items' => $items];
        $session->set(self::SESSION_KEY, $all);

        return $token;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array{restored: int, skipped: int}
     */
    private function restoreTransactions(User $user, array $items): array
    {
        $accounts = $this->indexById($this->em->getRepository(Account::class)->findBy(['owner' => $user, 'id' => array_unique(array_filter(array_column($items, 'account')))]));
        $categories = $this->indexById($this->em->getRepository(Category::class)->findBy(['owner' => $user, 'id' => array_unique(array_filter(array_column($items, 'category')))]));
        $rules = [];
        foreach ($this->em->getRepository(RecurringTransaction::class)->findBy(['id' => array_unique(array_filter(array_column($items, 'rule')))]) as $rule) {
            if ($rule->getOwner()?->getId() === $user->getId()) {
                $rules[$rule->getId()] = $rule;
            }
        }

        $restored = $skipped = 0;
        $this->em->wrapInTransaction(function () use ($items, $accounts, $categories, $rules, &$restored, &$skipped): void {
            foreach ($items as $item) {
                $account = $accounts[$item['account']] ?? null;
                $category = $categories[$item['category']] ?? null;
                $type = TransactionType::tryFrom((string) $item['type']);
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $item['date']);
                if (null === $account || null === $category || null === $type || false === $date) {
                    ++$skipped;
                    continue;
                }

                // Le lien avec la règle récurrente est rétabli, sauf si cette échéance existe déjà (contrainte d'unicité).
                $rule = $rules[$item['rule'] ?? 0] ?? null;
                if (null !== $rule && $this->em->getRepository(Transaction::class)->count(['recurringTransaction' => $rule, 'date' => $date]) > 0) {
                    $rule = null;
                }

                $this->em->persist(
                    (new Transaction())
                        ->setAccount($account)->setCategory($category)->setType($type)->setDate($date)
                        ->setLabel($item['label'])->setAmount($item['amount'])->setNote($item['note'])
                        ->setRecurringTransaction($rule)
                );
                ++$restored;
                // La contrainte d'unicité se vérifie à l'écriture : on écrit au fur et à mesure pour que le contrôle ci-dessus voie les lignes déjà recréées.
                $this->em->flush();
            }
        });

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array{restored: int, skipped: int}
     */
    private function restoreTransfers(User $user, array $items): array
    {
        $restored = $skipped = 0;
        foreach ($items as $item) {
            $from = $this->em->getRepository(Account::class)->findOneBy(['id' => $item['from'], 'owner' => $user]);
            $to = $this->em->getRepository(Account::class)->findOneBy(['id' => $item['to'], 'owner' => $user]);
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $item['date']);
            if (null === $from || null === $to || false === $date || $from->getCurrency() !== $to->getCurrency()) {
                ++$skipped;
                continue;
            }
            $this->em->persist((new Transfer())->setFromAccount($from)->setToAccount($to)->setDate($date)->setAmount($item['amount'])->setNote($item['note']));
            ++$restored;
        }
        $this->em->flush();

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /**
     * @param list<Account|Category> $entities
     *
     * @return array<int, Account|Category>
     */
    private function indexById(array $entities): array
    {
        $indexed = [];
        foreach ($entities as $entity) {
            $indexed[$entity->getId()] = $entity;
        }

        return $indexed;
    }
}
