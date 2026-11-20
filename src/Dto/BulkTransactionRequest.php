<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Action en lot sur des transactions cochées dans la liste (formulaire). */
final readonly class BulkTransactionRequest
{
    public const DELETE = 'delete';
    public const CATEGORY = 'category';
    public const ACCOUNT = 'account';
    public const MAX_SELECTION = 300;

    /**
     * @param list<string> $ids identifiants des transactions cochées
     */
    public function __construct(
        #[Assert\Choice(choices: [self::DELETE, self::CATEGORY, self::ACCOUNT], message: 'Action inconnue.')]
        public string $action = '',

        #[Assert\Count(min: 1, max: self::MAX_SELECTION, minMessage: 'Cochez au moins une transaction.', maxMessage: 'Sélection trop grande ({{ limit }} transactions au plus).')]
        #[Assert\All([new Assert\Regex(pattern: '/^\d{1,10}$/', message: 'Identifiant invalide.')])]
        public array $ids = [],

        /** Catégorie de destination (action « category »). */
        #[Assert\Regex(pattern: '/^\d{1,10}$/', message: 'Catégorie invalide.')]
        public ?string $category = null,

        /** Compte de destination (action « account »). */
        #[Assert\Regex(pattern: '/^\d{1,10}$/', message: 'Compte invalide.')]
        public ?string $account = null,

        /** Chaîne de requête de la liste d'où l'on vient : on y retourne en gardant filtres et onglet. */
        #[Assert\Length(max: 500)]
        public string $return = '',

        #[Assert\NotBlank(message: 'Jeton de sécurité manquant.')]
        public string $_token = '',
    ) {
    }

    /** @return list<int> */
    public function transactionIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->ids)));
    }

    public function categoryId(): ?int
    {
        return null !== $this->category && '' !== $this->category ? (int) $this->category : null;
    }

    public function accountId(): ?int
    {
        return null !== $this->account && '' !== $this->account ? (int) $this->account : null;
    }
}
