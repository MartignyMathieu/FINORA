<?php

namespace App\Dto;

use App\Enum\TransactionType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Filtres des transactions (chaîne de requête) : liste, totaux et export CSV partagent les mêmes critères.
 * Tous facultatifs ; un critère vide est ignoré.
 */
readonly class TransactionFilter
{
    public function __construct(
        /** « all », « income » ou « expense » : mêmes valeurs que les onglets de la liste. */
        #[Assert\Choice(choices: ['all', 'income', 'expense'], message: 'Type invalide : all, income ou expense.')]
        public string $type = 'all',

        /** Texte recherché dans le libellé ou la catégorie. */
        #[Assert\Length(max: 100)]
        public string $q = '',

        /** Première date incluse (AAAA-MM-JJ). */
        #[Assert\Date(message: 'Date de début invalide (AAAA-MM-JJ).')]
        public ?string $from = null,

        /** Dernière date incluse (AAAA-MM-JJ). */
        #[Assert\Date(message: 'Date de fin invalide (AAAA-MM-JJ).')]
        public ?string $to = null,

        /** Montant minimum (valeur absolue, dans la devise de chaque compte : aucune conversion). */
        #[Assert\Regex(pattern: '/^\d{1,10}([.,]\d{1,2})?$/', message: 'Montant minimum invalide.')]
        public ?string $min = null,

        /** Montant maximum (valeur absolue, dans la devise de chaque compte : aucune conversion). */
        #[Assert\Regex(pattern: '/^\d{1,10}([.,]\d{1,2})?$/', message: 'Montant maximum invalide.')]
        public ?string $max = null,

        /** Identifiant d'un compte de l'utilisateur. */
        #[Assert\Regex(pattern: '/^\d{1,10}$/', message: 'Compte invalide.')]
        public ?string $account = null,

        /** Identifiant d'une catégorie de l'utilisateur. */
        #[Assert\Regex(pattern: '/^\d{1,10}$/', message: 'Catégorie invalide.')]
        public ?string $category = null,
    ) {
    }

    public function transactionType(): ?TransactionType
    {
        return TransactionType::tryFrom($this->type);
    }

    public function searchText(): string
    {
        return trim($this->q);
    }

    public function fromDate(): ?\DateTimeImmutable
    {
        return $this->parseDate($this->from);
    }

    public function toDate(): ?\DateTimeImmutable
    {
        return $this->parseDate($this->to);
    }

    /** Montant minimum au format décimal « 12.50 » (virgule acceptée à la saisie), ou null. */
    public function minAmount(): ?string
    {
        return $this->parseAmount($this->min);
    }

    public function maxAmount(): ?string
    {
        return $this->parseAmount($this->max);
    }

    public function accountId(): ?int
    {
        return $this->parseId($this->account);
    }

    public function categoryId(): ?int
    {
        return $this->parseId($this->category);
    }

    /** Nombre de critères avancés actifs (période, montants, compte, catégorie) : le type et la recherche ont leur propre barre. */
    public function advancedCount(): int
    {
        return \count(array_filter([
            null !== $this->fromDate() || null !== $this->toDate(),
            null !== $this->minAmount() || null !== $this->maxAmount(),
            null !== $this->accountId(),
            null !== $this->categoryId(),
        ]));
    }

    public function isEmpty(): bool
    {
        return [] === $this->toQuery();
    }

    /**
     * Paramètres d'adresse équivalents (seulement ceux qui filtrent), pour construire des liens qui gardent le filtre.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'type' => 'all' !== $this->type && null !== $this->transactionType() ? $this->type : null,
            'q' => '' !== $this->searchText() ? $this->searchText() : null,
            'from' => null !== $this->fromDate() ? $this->from : null,
            'to' => null !== $this->toDate() ? $this->to : null,
            'min' => null !== $this->minAmount() ? $this->minAmount() : null,
            'max' => null !== $this->maxAmount() ? $this->maxAmount() : null,
            'account' => null !== $this->accountId() ? (string) $this->accountId() : null,
            'category' => null !== $this->categoryId() ? (string) $this->categoryId() : null,
        ], static fn (?string $value): bool => null !== $value);
    }

    /**
     * Les mêmes paramètres, sans certains critères.
     *
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    public function without(array $keys): array
    {
        return array_diff_key($this->toQuery(), array_flip($keys));
    }

    #[Assert\Callback]
    public function validateRanges(ExecutionContextInterface $context): void
    {
        $from = $this->fromDate();
        $to = $this->toDate();
        if (null !== $from && null !== $to && $to < $from) {
            $context->buildViolation('La date de fin ne peut pas précéder la date de début.')->atPath('to')->addViolation();
        }

        $min = $this->minAmount();
        $max = $this->maxAmount();
        if (null !== $min && null !== $max && (float) $max < (float) $min) {
            $context->buildViolation('Le montant maximum ne peut pas être inférieur au minimum.')->atPath('max')->addViolation();
        }
    }

    private function parseDate(?string $date): ?\DateTimeImmutable
    {
        if (null === $date || '' === $date) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return false === $parsed || $parsed->format('Y-m-d') !== $date ? null : $parsed;
    }

    private function parseAmount(?string $amount): ?string
    {
        if (null === $amount || 1 !== preg_match('/^\d{1,10}([.,]\d{1,2})?$/', $amount)) {
            return null;
        }

        return number_format((float) str_replace(',', '.', $amount), 2, '.', '');
    }

    private function parseId(?string $id): ?int
    {
        return null !== $id && 1 === preg_match('/^\d{1,10}$/', $id) ? (int) $id : null;
    }
}
