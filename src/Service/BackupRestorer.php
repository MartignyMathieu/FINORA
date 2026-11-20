<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\GoalContribution;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\Icon;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Restauration d'une sauvegarde produite par BackupExporter : elle REMPLACE toutes les données de l'utilisateur (comptes,
 * catégories, transactions, récurrences, budgets, objectifs, virements). Son compte (adresse e-mail, mot de passe) n'est pas touché.
 *
 * Tout se passe dans une seule transaction de base de données : si une ligne du fichier est invalide, rien n'est modifié (les données
 * actuelles sont conservées). Chaque élément est contrôlé par les mêmes règles que les formulaires de l'application.
 */
final class BackupRestorer
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** Nombre maximal de lignes par section. */
    private const LIMITS = ['accounts' => 200, 'categories' => 500, 'transactions' => 20000, 'recurring' => 500, 'budgets' => 5000, 'goals' => 200, 'transfers' => 5000];
    private const LABELS = ['accounts' => 'Compte', 'categories' => 'Catégorie', 'transactions' => 'Transaction', 'recurring' => 'Récurrence', 'budgets' => 'Budget', 'goals' => 'Objectif', 'transfers' => 'Virement'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly UserDataPurger $purger,
    ) {
    }

    /**
     * Lit et contrôle la structure du document (format, version, sections, tailles). Le contenu de chaque ligne est contrôlé à la restauration.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException avec un message destiné à l'utilisateur
     */
    public function parse(string $json): array
    {
        self::checkSize(\strlen($json));
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3);
        }

        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Ce fichier n\'est pas une sauvegarde FINORA : le contenu n\'est pas un JSON valide.');
        }

        if (!\is_array($data) || BackupExporter::FORMAT !== ($data['format'] ?? null)) {
            throw new \InvalidArgumentException('Ce fichier n\'est pas une sauvegarde FINORA.');
        }
        if (BackupExporter::VERSION !== ($data['version'] ?? null)) {
            throw new \InvalidArgumentException(sprintf('Version de sauvegarde non prise en charge (%s) : cette application lit la version %d.', \is_scalar($data['version'] ?? null) ? (string) $data['version'] : '?', BackupExporter::VERSION));
        }

        foreach (self::LIMITS as $section => $limit) {
            $rows = $data[$section] ?? [];
            if (!\is_array($rows) || !array_is_list($rows)) {
                throw new \InvalidArgumentException(sprintf('Sauvegarde invalide : la section « %s » est mal formée.', $section));
            }
            if (\count($rows) > $limit) {
                throw new \InvalidArgumentException(sprintf('Sauvegarde trop volumineuse : la section « %s » dépasse %d lignes.', $section, $limit));
            }
            foreach ($rows as $index => $row) {
                if (!\is_array($row)) {
                    throw new \InvalidArgumentException(sprintf('%s n°%d : ligne mal formée.', self::LABELS[$section], $index + 1));
                }
            }
            $data[$section] = $rows;
        }

        return $data;
    }

    /** Refuse un fichier trop volumineux (testable sans fabriquer un fichier de 20 Mo). */
    public static function checkSize(int $bytes): void
    {
        if ($bytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Le fichier dépasse 20 Mo.');
        }
    }

    /**
     * Remplace toutes les données de l'utilisateur par celles de la sauvegarde.
     *
     * @param array<string, mixed> $data résultat de parse()
     *
     * @return array<string, int> nombre d'éléments restaurés par section
     *
     * @throws \InvalidArgumentException si une ligne est invalide : alors rien n'a été modifié
     */
    public function restore(User $user, array $data): array
    {
        try {
            return $this->em->wrapInTransaction(function () use ($user, $data): array {
                $this->purger->purgeData($user);

                return $this->build($user, $data);
            });
        } catch (\InvalidArgumentException $e) {
            // Les éléments créés pour rien ne doivent pas rester dans le gestionnaire d'entités.
            $this->em->clear();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, int>
     */
    private function build(User $user, array $data): array
    {
        /** @var array<int|string, Account> $accounts */
        $accounts = [];
        foreach ($data['accounts'] as $i => $row) {
            $account = $this->guard('accounts', $i, fn () => (new Account())->setOwner($user)
                ->setName($this->string($row, 'name', 100))
                ->setType($this->enum($row, 'type', AccountType::class))
                ->setCurrency($this->string($row, 'currency', 3))
                ->setInitialBalance($this->decimal($row, 'initialBalance', true)));
            $this->validate('accounts', $i, $account);
            $this->em->persist($account);
            $accounts[$this->key($row, 'id', 'accounts', $i, $accounts)] = $account;
        }

        /** @var array<int|string, Category> $categories */
        $categories = [];
        $categoryNames = [];
        foreach ($data['categories'] as $i => $row) {
            $category = $this->guard('categories', $i, function () use ($user, $row, &$categoryNames) {
                $name = $this->string($row, 'name', 100);
                // Un nom de catégorie est unique par utilisateur (contrainte de la base).
                if (isset($categoryNames[$name])) {
                    throw new \InvalidArgumentException(sprintf('une catégorie « %s » existe déjà dans la sauvegarde.', $name));
                }
                $categoryNames[$name] = true;

                return (new Category())->setOwner($user)->setName($name)
                    ->setIcon($this->optionalEnum($row, 'icon', Icon::class))
                    ->setColor($this->optionalString($row, 'color', 7));
            });
            $this->validate('categories', $i, $category, ['name', 'color', 'icon']);
            $this->em->persist($category);
            $categories[$this->key($row, 'id', 'categories', $i, $categories)] = $category;
        }

        /** @var array<int|string, RecurringTransaction> $rules */
        $rules = [];
        foreach ($data['recurring'] as $i => $row) {
            $rule = $this->guard('recurring', $i, fn () => (new RecurringTransaction())
                ->setLabel($this->string($row, 'label', 255))
                ->setAmount($this->decimal($row, 'amount'))
                ->setType($this->enum($row, 'type', TransactionType::class))
                ->setNote($this->optionalString($row, 'note', 1000))
                ->setAccount($this->reference($row, 'account', $accounts))
                ->setCategory($this->reference($row, 'category', $categories))
                ->setFrequency($this->enum($row, 'frequency', RecurrenceFrequency::class))
                ->setStartDate($this->date($row, 'startDate'))
                ->setEndDate($this->optionalDate($row, 'endDate'))
                ->setLastGeneratedDate($this->optionalDate($row, 'lastGeneratedDate'))
                ->setActive($this->boolean($row, 'active')));
            $this->validate('recurring', $i, $rule);
            $this->em->persist($rule);
            $rules[$this->key($row, 'id', 'recurring', $i, $rules)] = $rule;
        }

        $seenOccurrences = [];
        foreach ($data['transactions'] as $i => $row) {
            $transaction = $this->guard('transactions', $i, function () use ($row, $accounts, $categories, $rules, &$seenOccurrences) {
                $rule = null;
                if (null !== ($row['recurring'] ?? null)) {
                    $rule = $this->reference($row, 'recurring', $rules);
                }
                $date = $this->date($row, 'date');
                if (null !== $rule) {
                    // Une échéance de récurrence n'existe qu'une fois (contrainte d'unicité de la base).
                    $occurrence = spl_object_id($rule).'|'.$date->format('Y-m-d');
                    if (isset($seenOccurrences[$occurrence])) {
                        throw new \InvalidArgumentException('cette échéance de récurrence apparaît deux fois.');
                    }
                    $seenOccurrences[$occurrence] = true;
                }

                return (new Transaction())
                    ->setAccount($this->reference($row, 'account', $accounts))
                    ->setCategory($this->reference($row, 'category', $categories))
                    ->setType($this->enum($row, 'type', TransactionType::class))
                    ->setDate($date)
                    ->setLabel($this->string($row, 'label', 255))
                    ->setAmount($this->decimal($row, 'amount'))
                    ->setNote($this->optionalString($row, 'note', 1000))
                    ->setRecurringTransaction($rule);
            });
            $this->validate('transactions', $i, $transaction);
            $this->em->persist($transaction);
        }

        $seenBudgets = [];
        foreach ($data['budgets'] as $i => $row) {
            $budget = $this->guard('budgets', $i, function () use ($row, $categories, &$seenBudgets) {
                $category = $this->reference($row, 'category', $categories);
                $month = $this->date($row, 'month');
                $key = spl_object_id($category).'|'.$month->format('Y-m');
                if (isset($seenBudgets[$key])) {
                    throw new \InvalidArgumentException('un budget existe déjà pour cette catégorie et ce mois.');
                }
                $seenBudgets[$key] = true;

                return (new Budget())->setCategory($category)->setMonth($month)
                    ->setAmount($this->decimal($row, 'amount'))
                    ->setCurrency($this->string($row, 'currency', 3));
            });
            $this->validate('budgets', $i, $budget, ['amount', 'currency', 'month']);
            $this->em->persist($budget);
        }

        $contributions = 0;
        foreach ($data['goals'] as $i => $row) {
            $goal = $this->guard('goals', $i, fn () => (new Goal())->setOwner($user)
                ->setName($this->string($row, 'name', 100))
                ->setTargetAmount($this->decimal($row, 'targetAmount'))
                ->setSavedAmount($this->decimal($row, 'savedAmount', true))
                ->setCurrency($this->string($row, 'currency', 3))
                ->setTargetDate($this->optionalDate($row, 'targetDate'))
                ->setIcon($this->optionalEnum($row, 'icon', Icon::class)));
            $this->validate('goals', $i, $goal);
            $this->em->persist($goal);

            $lines = $row['contributions'] ?? [];
            if (!\is_array($lines) || !array_is_list($lines) || \count($lines) > 5000) {
                throw new \InvalidArgumentException(sprintf('Objectif n°%d : historique mal formé.', $i + 1));
            }
            foreach ($lines as $line) {
                $contribution = $this->guard('goals', $i, fn () => (new GoalContribution())->setGoal($goal)
                    ->setAmount($this->decimal(\is_array($line) ? $line : [], 'amount', true))
                    ->setDate($this->date(\is_array($line) ? $line : [], 'date'))
                    ->setNote($this->optionalString(\is_array($line) ? $line : [], 'note', 1000)));
                $this->em->persist($contribution);
                ++$contributions;
            }
        }

        foreach ($data['transfers'] as $i => $row) {
            $transfer = $this->guard('transfers', $i, fn () => (new Transfer())
                ->setFromAccount($this->reference($row, 'from', $accounts))
                ->setToAccount($this->reference($row, 'to', $accounts))
                ->setAmount($this->decimal($row, 'amount'))
                ->setDate($this->date($row, 'date'))
                ->setNote($this->optionalString($row, 'note', 1000)));
            $this->validate('transfers', $i, $transfer);
            $this->em->persist($transfer);
        }

        $this->flush();

        return [
            'accounts' => \count($accounts),
            'categories' => \count($categories),
            'transactions' => \count($data['transactions']),
            'recurring' => \count($rules),
            'budgets' => \count($data['budgets']),
            'goals' => \count($data['goals']),
            'contributions' => $contributions,
            'transfers' => \count($data['transfers']),
        ];
    }

    // ------------------------------------------------------------------------------------------------ lecture des champs

    /**
     * Exécute la lecture d'une ligne ; toute erreur de contenu est signalée avec le numéro de la ligne.
     *
     * @template T
     *
     * @param callable(): T $build
     *
     * @return T
     */
    private function guard(string $section, int $index, callable $build): mixed
    {
        try {
            return $build();
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(sprintf('%s n°%d : %s', self::LABELS[$section], $index + 1, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Contrôle une entité avec ses règles de validation habituelles. Pour les entités à contrainte d'unicité portant sur une relation
     * non encore écrite (catégorie, budget), on contrôle seulement leurs propriétés : l'unicité est vérifiée à part.
     *
     * @param list<string>|null $properties
     */
    private function validate(string $section, int $index, object $entity, ?array $properties = null): void
    {
        $violations = null === $properties
            ? $this->validator->validate($entity)
            : array_merge(...array_map(fn (string $property): array => iterator_to_array($this->validator->validateProperty($entity, $property)), $properties));
        foreach ($violations as $violation) {
            throw new \InvalidArgumentException(sprintf('%s n°%d : %s', self::LABELS[$section], $index + 1, $violation->getMessage()));
        }
    }

    private function flush(): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Filet de sécurité : les doublons connus sont détectés plus haut, avec le numéro de la ligne.
            throw new \InvalidArgumentException('Sauvegarde invalide : elle contient des doublons.');
        }
    }

    /** Numéro propre au fichier d'un élément (son « id » d'origine), unique dans la section. */
    private function key(array $row, string $field, string $section, int $index, array $existing): int|string
    {
        $value = $row[$field] ?? null;
        if (!\is_int($value) && !(\is_string($value) && '' !== $value && \strlen($value) <= 40)) {
            throw new \InvalidArgumentException(sprintf('%s n°%d : identifiant manquant.', self::LABELS[$section], $index + 1));
        }
        if (isset($existing[$value])) {
            throw new \InvalidArgumentException(sprintf('%s n°%d : identifiant en double (%s).', self::LABELS[$section], $index + 1, $value));
        }

        return $value;
    }

    /**
     * @template T of object
     *
     * @param array<int|string, T> $map
     *
     * @return T
     */
    private function reference(array $row, string $field, array $map): object
    {
        $value = $row[$field] ?? null;
        if (!\is_int($value) && !\is_string($value)) {
            throw new \InvalidArgumentException(sprintf('champ « %s » manquant.', $field));
        }
        if (!isset($map[$value])) {
            throw new \InvalidArgumentException(sprintf('« %s » fait référence à un élément absent de la sauvegarde (%s).', $field, $value));
        }

        return $map[$value];
    }

    private function string(array $row, string $field, int $max): string
    {
        $value = $row[$field] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('champ « %s » manquant ou vide.', $field));
        }
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException(sprintf('champ « %s » trop long (%d caractères au plus).', $field, $max));
        }

        return $value;
    }

    private function optionalString(array $row, string $field, int $max): ?string
    {
        $value = $row[$field] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) || mb_strlen($value) > $max) {
            throw new \InvalidArgumentException(sprintf('champ « %s » invalide.', $field));
        }

        return $value;
    }

    private function decimal(array $row, string $field, bool $allowNegative = false): string
    {
        $value = $row[$field] ?? null;
        if (\is_int($value) || \is_float($value)) {
            $value = number_format((float) $value, 2, '.', '');
        }
        $pattern = $allowNegative ? '/^-?\d{1,10}(\.\d{1,2})?$/' : '/^\d{1,10}(\.\d{1,2})?$/';
        if (!\is_string($value) || 1 !== preg_match($pattern, $value)) {
            throw new \InvalidArgumentException(sprintf('champ « %s » invalide (nombre décimal attendu).', $field));
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function date(array $row, string $field): \DateTimeImmutable
    {
        $value = $row[$field] ?? null;
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('champ « %s » invalide (date AAAA-MM-JJ attendue).', $field));
        }

        return $date;
    }

    private function optionalDate(array $row, string $field): ?\DateTimeImmutable
    {
        return null === ($row[$field] ?? null) || '' === $row[$field] ? null : $this->date($row, $field);
    }

    private function boolean(array $row, string $field): bool
    {
        if (!\is_bool($row[$field] ?? null)) {
            throw new \InvalidArgumentException(sprintf('champ « %s » invalide (vrai ou faux attendu).', $field));
        }

        return $row[$field];
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function enum(array $row, string $field, string $class): \BackedEnum
    {
        $value = $row[$field] ?? null;
        $case = \is_string($value) || \is_int($value) ? $class::tryFrom($value) : null;
        if (null === $case) {
            throw new \InvalidArgumentException(sprintf('champ « %s » : valeur inconnue.', $field));
        }

        return $case;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function optionalEnum(array $row, string $field, string $class): ?\BackedEnum
    {
        return null === ($row[$field] ?? null) || '' === $row[$field] ? null : $this->enum($row, $field, $class);
    }
}
