<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\BudgetAlerts;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/** Alertes de budget disponibles dans toutes les pages (pastille du menu, bannières). Calculées une seule fois par requête. */
final class BudgetAlertsExtension
{
    /** @var list<array<string, mixed>>|null */
    private ?array $alerts = null;

    public function __construct(
        private readonly BudgetAlerts $budgetAlerts,
        private readonly Security $security,
    ) {
    }

    /** @return list<array<string, mixed>> vide si personne n'est connecté */
    #[AsTwigFunction('budget_alerts')]
    public function alerts(): array
    {
        if (!$this->security->getUser() instanceof User) {
            return [];
        }

        return $this->alerts ??= $this->budgetAlerts->current();
    }

    #[AsTwigFunction('budget_alerts_signature')]
    public function signature(): string
    {
        return BudgetAlerts::signature($this->alerts());
    }
}
