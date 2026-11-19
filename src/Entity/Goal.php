<?php

namespace App\Entity;

use App\Enum\Icon;
use App\Repository\GoalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Objectif d'épargne. */
#[ORM\Entity(repositoryClass: GoalRepository::class)]
class Goal implements OwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\Positive]
    private ?string $targetAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private string $savedAmount = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    #[Assert\NotBlank]
    #[Assert\Currency]
    private string $currency = 'EUR';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $targetDate = null;

    #[ORM\Column(enumType: Icon::class, nullable: true)]
    private ?Icon $icon = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getTargetAmount(): ?string
    {
        return $this->targetAmount;
    }

    public function setTargetAmount(?string $targetAmount): static
    {
        $this->targetAmount = $targetAmount;

        return $this;
    }

    public function getSavedAmount(): string
    {
        return $this->savedAmount;
    }

    public function setSavedAmount(?string $savedAmount): static
    {
        // Champ vide : rien d'épargné pour l'instant.
        $this->savedAmount = $savedAmount ?? '0.00';

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function getTargetDate(): ?\DateTimeImmutable
    {
        return $this->targetDate;
    }

    public function setTargetDate(?\DateTimeImmutable $targetDate): static
    {
        $this->targetDate = $targetDate;

        return $this;
    }

    public function getIcon(): ?Icon
    {
        return $this->icon;
    }

    public function setIcon(?Icon $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /** Avancement en pourcentage, borné à 100. */
    public function getProgress(): int
    {
        $target = (float) $this->targetAmount;

        return $target > 0 ? (int) min(100, round((float) $this->savedAmount / $target * 100)) : 0;
    }

    /** Objectif atteint ou dépassé. */
    public function isReached(): bool
    {
        return (float) $this->targetAmount > 0 && (float) $this->savedAmount >= (float) $this->targetAmount;
    }

    /** Montant qu'il reste à épargner (jamais négatif). */
    public function getRemaining(): float
    {
        return max(0.0, (float) $this->targetAmount - (float) $this->savedAmount);
    }

    /**
     * Mois entre le mois de $today et celui de l'échéance : 0 si c'est ce mois-ci, négatif si l'échéance est
     * passée, null sans échéance.
     */
    public function getMonthsLeft(\DateTimeInterface $today): ?int
    {
        if (null === $this->targetDate) {
            return null;
        }

        return ((int) $this->targetDate->format('Y') - (int) $today->format('Y')) * 12
            + ((int) $this->targetDate->format('n') - (int) $today->format('n'));
    }

    /** Échéance dépassée sans que l'objectif soit atteint. */
    public function isOverdue(\DateTimeInterface $today): bool
    {
        $months = $this->getMonthsLeft($today);

        return null !== $months && $months < 0 && !$this->isReached();
    }

    /**
     * Effort d'épargne mensuel pour atteindre l'objectif à son échéance (le reste à épargner réparti sur les mois
     * restants, au moins un). Null si l'objectif est atteint, sans échéance ou si l'échéance est passée.
     */
    public function getRequiredMonthly(\DateTimeInterface $today): ?float
    {
        $months = $this->getMonthsLeft($today);
        if (null === $months || $months < 0 || $this->isReached()) {
            return null;
        }

        return $this->getRemaining() / max(1, $months);
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
