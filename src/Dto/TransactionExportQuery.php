<?php

namespace App\Dto;

use App\Enum\TransactionType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Paramètres de l'export CSV des transactions (chaîne de requête). Tous facultatifs. */
final readonly class TransactionExportQuery
{
    public function __construct(
        /** « all », « income » ou « expense » : mêmes valeurs que les onglets de la liste. */
        #[Assert\Choice(choices: ['all', 'income', 'expense'], message: 'Type invalide : all, income ou expense.')]
        public string $type = 'all',

        /** Texte recherché dans le libellé ou la catégorie, comme la recherche de la liste. */
        #[Assert\Length(max: 100)]
        public string $q = '',

        /** Première date incluse (AAAA-MM-JJ). */
        #[Assert\Date(message: 'Date de début invalide (AAAA-MM-JJ).')]
        public ?string $from = null,

        /** Dernière date incluse (AAAA-MM-JJ). */
        #[Assert\Date(message: 'Date de fin invalide (AAAA-MM-JJ).')]
        public ?string $to = null,

        /** « fr » : séparateur « ; » et virgule décimale (Excel en français) ; « intl » : séparateur « , » et point décimal. */
        #[Assert\Choice(choices: ['fr', 'intl'], message: 'Format invalide : fr ou intl.')]
        public string $format = 'fr',
    ) {
    }

    public function transactionType(): ?TransactionType
    {
        return TransactionType::tryFrom($this->type);
    }

    public function fromDate(): ?\DateTimeImmutable
    {
        return $this->parse($this->from);
    }

    public function toDate(): ?\DateTimeImmutable
    {
        return $this->parse($this->to);
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        $from = $this->fromDate();
        $to = $this->toDate();
        if (null !== $from && null !== $to && $to < $from) {
            $context->buildViolation('La date de fin ne peut pas précéder la date de début.')->atPath('to')->addViolation();
        }
    }

    private function parse(?string $date): ?\DateTimeImmutable
    {
        if (null === $date || '' === $date) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return false === $parsed ? null : $parsed;
    }
}
