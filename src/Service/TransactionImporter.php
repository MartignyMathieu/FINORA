<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionType;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import de transactions depuis un CSV déjà lu (voir CsvReader) : correspondance des colonnes, analyse ligne par ligne,
 * détection des doublons, puis enregistrement en une seule transaction de base de données.
 *
 * Tout reste dans le périmètre de l'utilisateur : comptes et catégories sont cherchés parmi les siens, jamais ailleurs.
 *
 * @phpstan-type Mapping array{date: ?int, label: ?int, amount: ?int, type: ?int, account: ?int, category: ?int, note: ?int}
 * @phpstan-type Row array{
 *     line: int, date: ?\DateTimeImmutable, label: string, amount: ?string, type: ?TransactionType, account: ?Account,
 *     category: ?Category, categoryName: ?string, note: ?string, errors: list<string>, duplicate: bool
 * }
 */
final class TransactionImporter
{
    /** Mots-clés (sans accents, en minuscules) reconnus dans les noms de colonnes pour proposer une correspondance. */
    private const COLUMN_HINTS = [
        'date' => ['date', 'jour', 'day'],
        'label' => ['libelle', 'label', 'description', 'intitule', 'objet', 'detail', 'memo', 'beneficiaire', 'payee'],
        'amount' => ['montant', 'amount', 'somme', 'valeur', 'value'],
        'type' => ['type', 'sens', 'nature'],
        'account' => ['compte', 'account'],
        'category' => ['categorie', 'category', 'rubrique'],
        'note' => ['note', 'commentaire', 'comment', 'remarque'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository $accounts,
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * Correspondance proposée d'après les noms de colonnes (sans accents ni casse) ; null si rien ne ressemble.
     *
     * @param list<string> $header
     *
     * @return Mapping
     */
    public function guessMapping(array $header): array
    {
        $normalized = array_map(static fn (string $name): string => self::normalize($name), $header);
        $mapping = ['date' => null, 'label' => null, 'amount' => null, 'type' => null, 'account' => null, 'category' => null, 'note' => null];
        $used = [];

        foreach (self::COLUMN_HINTS as $field => $hints) {
            foreach ($hints as $hint) {
                foreach ($normalized as $index => $name) {
                    if (isset($used[$index])) {
                        continue;
                    }
                    if ($name === $hint || str_starts_with($name, $hint)) {
                        $mapping[$field] = $index;
                        $used[$index] = true;
                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Analyse toutes les lignes : valeurs lues, erreurs de chaque ligne, doublons déjà présents en base.
     *
     * @param list<list<string>> $rows
     * @param Mapping            $mapping
     *
     * @return array{rows: list<Row>, valid: int, invalid: int, duplicates: int, newCategories: list<string>}
     */
    public function analyse(User $user, array $rows, array $mapping, Account $defaultAccount, Category $fallbackCategory, bool $createCategories): array
    {
        $accountsByName = [];
        foreach ($this->accounts->findBy(['owner' => $user]) as $account) {
            $accountsByName[mb_strtolower($account->getName())] = $account;
        }
        $categoriesByName = [];
        foreach ($this->categories->findBy(['owner' => $user]) as $category) {
            $categoriesByName[mb_strtolower($category->getName())] = $category;
        }

        $parsed = [];
        $newCategories = [];
        foreach ($rows as $index => $cells) {
            $row = $this->parseRow($index + 2, $cells, $mapping, $defaultAccount, $fallbackCategory, $createCategories, $accountsByName, $categoriesByName);
            if (null !== $row['categoryName'] && null === $row['category'] && [] === $row['errors']) {
                $newCategories[mb_strtolower($row['categoryName'])] = $row['categoryName'];
            }
            $parsed[] = $row;
        }

        $this->flagDuplicates($user, $parsed);

        $valid = $invalid = $duplicates = 0;
        foreach ($parsed as $row) {
            if ([] !== $row['errors']) {
                ++$invalid;
            } elseif ($row['duplicate']) {
                ++$duplicates;
            } else {
                ++$valid;
            }
        }

        return ['rows' => $parsed, 'valid' => $valid, 'invalid' => $invalid, 'duplicates' => $duplicates, 'newCategories' => array_values($newCategories)];
    }

    /**
     * Enregistre les lignes valides (et les doublons si $skipDuplicates est faux) ; les lignes invalides sont toujours ignorées.
     * Tout ou rien : une erreur de base annule l'import entier.
     *
     * @param list<Row> $rows
     *
     * @return array{imported: int, skippedDuplicates: int, invalid: int, categoriesCreated: int}
     */
    public function import(User $user, array $rows, bool $skipDuplicates): array
    {
        return $this->em->wrapInTransaction(function () use ($user, $rows, $skipDuplicates): array {
            $created = [];
            $result = ['imported' => 0, 'skippedDuplicates' => 0, 'invalid' => 0, 'categoriesCreated' => 0];

            foreach ($rows as $row) {
                if ([] !== $row['errors']) {
                    ++$result['invalid'];
                    continue;
                }
                if ($row['duplicate'] && $skipDuplicates) {
                    ++$result['skippedDuplicates'];
                    continue;
                }

                $category = $row['category'];
                if (null === $category) {
                    $key = mb_strtolower((string) $row['categoryName']);
                    if (!isset($created[$key])) {
                        $created[$key] = (new Category())->setOwner($user)->setName((string) $row['categoryName']);
                        $this->em->persist($created[$key]);
                        ++$result['categoriesCreated'];
                    }
                    $category = $created[$key];
                }

                $this->em->persist(
                    (new Transaction())
                        ->setAccount($row['account'])
                        ->setCategory($category)
                        ->setType($row['type'])
                        ->setDate($row['date'])
                        ->setLabel($row['label'])
                        ->setAmount($row['amount'])
                        ->setNote($row['note'])
                );
                ++$result['imported'];
            }

            $this->em->flush();

            return $result;
        });
    }

    /**
     * @param list<string>             $cells
     * @param Mapping                  $mapping
     * @param array<string, Account>   $accountsByName
     * @param array<string, Category>  $categoriesByName
     *
     * @return Row
     */
    private function parseRow(int $line, array $cells, array $mapping, Account $defaultAccount, Category $fallbackCategory, bool $createCategories, array $accountsByName, array $categoriesByName): array
    {
        $cell = static fn (?int $column): string => null === $column ? '' : trim($cells[$column] ?? '');
        $errors = [];

        $date = CsvValueParser::parseDate($cell($mapping['date']));
        if (null === $date) {
            $errors[] = '' === $cell($mapping['date']) ? 'Date manquante.' : sprintf('Date non reconnue : « %s ».', CsvValueParser::text($cell($mapping['date']), 30));
        }

        $label = CsvValueParser::text($cell($mapping['label']), 255);
        if ('' === $label) {
            $errors[] = 'Libellé manquant.';
        }

        $amount = CsvValueParser::parseAmount($cell($mapping['amount']));
        $type = null;
        if (null === $amount) {
            $errors[] = '' === $cell($mapping['amount']) ? 'Montant manquant.' : sprintf('Montant non reconnu : « %s ».', CsvValueParser::text($cell($mapping['amount']), 30));
        } elseif ((float) $amount === 0.0) {
            $errors[] = 'Le montant est nul.';
            $amount = null;
        } elseif (null !== $mapping['type']) {
            // Colonne « Type » : elle donne le sens, le montant est pris en valeur absolue.
            $type = CsvValueParser::parseType($cell($mapping['type']));
            if (null === $type) {
                $errors[] = sprintf('Type non reconnu : « %s » (attendu : revenu ou dépense).', CsvValueParser::text($cell($mapping['type']), 30));
            }
            $amount = ltrim($amount, '-');
        } else {
            // Sans colonne « Type » : le signe donne le sens (négatif = dépense).
            $type = str_starts_with($amount, '-') ? TransactionType::Expense : TransactionType::Income;
            $amount = ltrim($amount, '-');
        }

        $account = $defaultAccount;
        if (null !== $mapping['account'] && '' !== $cell($mapping['account'])) {
            $account = $accountsByName[mb_strtolower($cell($mapping['account']))] ?? null;
            if (null === $account) {
                $errors[] = sprintf('Compte inconnu : « %s ».', CsvValueParser::text($cell($mapping['account']), 40));
            }
        }

        $category = $fallbackCategory;
        $categoryName = null;
        $wanted = CsvValueParser::text($cell($mapping['category']), 100);
        if (null !== $mapping['category'] && '' !== $wanted) {
            $category = $categoriesByName[mb_strtolower($wanted)] ?? null;
            if (null === $category) {
                if ($createCategories) {
                    $categoryName = $wanted;
                } else {
                    $category = $fallbackCategory;
                }
            }
        }

        $note = CsvValueParser::text($cell($mapping['note']), 1000);

        return [
            'line' => $line,
            'date' => $date,
            'label' => $label,
            'amount' => $amount,
            'type' => $type,
            'account' => $account,
            'category' => $category,
            'categoryName' => $categoryName,
            'note' => '' === $note ? null : $note,
            'errors' => $errors,
            'duplicate' => false,
        ];
    }

    /**
     * Marque les lignes déjà présentes en base (même compte, date, sens, montant et libellé). Comptage par occurrence : un fichier
     * importé deux fois n'ajoute rien, et deux achats identiques le même jour restent possibles.
     *
     * @param list<Row> $rows
     */
    private function flagDuplicates(User $user, array &$rows): void
    {
        $dates = array_filter(array_map(static fn (array $row): ?\DateTimeImmutable => [] === $row['errors'] ? $row['date'] : null, $rows));
        if ([] === $dates) {
            return;
        }

        $existing = [];
        $found = $this->em->createQuery(
            'SELECT t FROM '.Transaction::class.' t JOIN t.account a WHERE a.owner = :user AND t.date BETWEEN :from AND :to'
        )
            ->setParameter('user', $user)
            ->setParameter('from', min($dates), Types::DATE_IMMUTABLE)
            ->setParameter('to', max($dates), Types::DATE_IMMUTABLE)
            ->getResult();
        foreach ($found as $transaction) {
            $key = $this->key($transaction->getAccount(), $transaction->getDate(), $transaction->getType(), (string) $transaction->getAmount(), (string) $transaction->getLabel());
            $existing[$key] = ($existing[$key] ?? 0) + 1;
        }

        foreach ($rows as &$row) {
            if ([] !== $row['errors']) {
                continue;
            }
            $key = $this->key($row['account'], $row['date'], $row['type'], (string) $row['amount'], $row['label']);
            if (($existing[$key] ?? 0) > 0) {
                --$existing[$key];
                $row['duplicate'] = true;
            }
        }
    }

    private function key(?Account $account, ?\DateTimeImmutable $date, ?TransactionType $type, string $amount, string $label): string
    {
        return implode('|', [$account?->getId(), $date?->format('Y-m-d'), $type?->value, number_format((float) $amount, 2, '.', ''), mb_strtolower(trim($label))]);
    }

    /** Minuscules sans accents, pour comparer des noms de colonnes. */
    private static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']);

        return $name;
    }
}
