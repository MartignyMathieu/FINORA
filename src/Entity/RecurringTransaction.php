<?php

namespace App\Entity;

use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use App\Repository\RecurringTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Règle de transaction récurrente (loyer, salaire, abonnement…).
 *
 * La règle ne compte pas dans les soldes : à chaque échéance elle génère une vraie Transaction
 * (voir RecurringTransactionGenerator). Modifier la règle n'affecte que les échéances à venir.
 */
#[ORM\Entity(repositoryClass: RecurringTransactionRepository::class)]
class RecurringTransaction implements OwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $label = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\Positive]
    private ?string $amount = null;

    #[ORM\Column(enumType: TransactionType::class)]
    #[Assert\NotNull]
    private ?TransactionType $type = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Account $account = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Category $category = null;

    #[ORM\Column(enumType: RecurrenceFrequency::class)]
    #[Assert\NotNull]
    private ?RecurrenceFrequency $frequency = null;

    /** Date de la première échéance. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startDate = null;

    /** Dernière date d'échéance possible ; null = sans fin. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    /** Date de la dernière échéance déjà transformée en transaction. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastGeneratedDate = null;

    /** Une règle en pause ne génère plus rien jusqu'à sa reprise (les échéances manquées sont alors rattrapées). */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Une règle appartient au propriétaire de son compte. */
    public function getOwner(): ?User
    {
        return $this->account?->getOwner();
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getType(): ?TransactionType
    {
        return $this->type;
    }

    public function setType(TransactionType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getFrequency(): ?RecurrenceFrequency
    {
        return $this->frequency;
    }

    public function setFrequency(RecurrenceFrequency $frequency): static
    {
        $this->frequency = $frequency;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getLastGeneratedDate(): ?\DateTimeImmutable
    {
        return $this->lastGeneratedDate;
    }

    public function setLastGeneratedDate(?\DateTimeImmutable $lastGeneratedDate): static
    {
        $this->lastGeneratedDate = $lastGeneratedDate;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Prochaine échéance à générer : la première date de la règle postérieure à la dernière génération
     * (ou la date de départ), ou null si la règle est terminée.
     */
    public function getNextDueDate(): ?\DateTimeImmutable
    {
        if (null === $this->frequency || null === $this->startDate) {
            return null;
        }

        for ($i = 0; ; ++$i) {
            $date = $this->frequency->occurrence($this->startDate, $i);
            if (null !== $this->endDate && $date > $this->endDate) {
                return null;
            }
            if (null === $this->lastGeneratedDate || $date > $this->lastGeneratedDate) {
                return $date;
            }
        }
    }

    /**
     * Échéances à générer jusqu'à $until inclus (postérieures à la dernière génération, dans les bornes
     * de la règle), de la plus ancienne à la plus récente, dans la limite de $limit dates.
     *
     * @return list<\DateTimeImmutable>
     */
    public function getDueDates(\DateTimeImmutable $until, int $limit = PHP_INT_MAX): array
    {
        if (null === $this->frequency || null === $this->startDate) {
            return [];
        }

        $dates = [];
        for ($i = 0; \count($dates) < $limit; ++$i) {
            $date = $this->frequency->occurrence($this->startDate, $i);
            if ($date > $until || (null !== $this->endDate && $date > $this->endDate)) {
                break;
            }
            if (null === $this->lastGeneratedDate || $date > $this->lastGeneratedDate) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /** Terminée : plus aucune échéance à venir. */
    public function isEnded(): bool
    {
        return null === $this->getNextDueDate();
    }

    /** Montant ramené à un équivalent mensuel (pour les totaux). */
    public function getMonthlyAmount(): float
    {
        return (float) $this->amount * ($this->frequency?->monthlyFactor() ?? 0.0);
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if ($this->startDate && $this->endDate && $this->endDate < $this->startDate) {
            $context->buildViolation('La date de fin ne peut pas précéder la première échéance.')
                ->atPath('endDate')
                ->addViolation();
        }
    }
}
