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

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
