<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paramètres de l'export CSV des transactions : les mêmes filtres que la liste (voir TransactionFilter), plus le format.
 */
readonly class TransactionExportQuery extends TransactionFilter
{
    public function __construct(
        string $type = 'all',
        string $q = '',
        ?string $from = null,
        ?string $to = null,
        ?string $min = null,
        ?string $max = null,
        ?string $account = null,
        ?string $category = null,

        /** « fr » : séparateur « ; » et virgule décimale (Excel en français) ; « intl » : séparateur « , » et point décimal. */
        #[Assert\Choice(choices: ['fr', 'intl'], message: 'Format invalide : fr ou intl.')]
        public string $format = 'fr',
    ) {
        parent::__construct($type, $q, $from, $to, $min, $max, $account, $category);
    }
}
