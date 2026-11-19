<?php

namespace App\Entity;

use App\Repository\BudgetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: BudgetRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_budget_category_month', columns: ['category_id', 'month'])]
#[UniqueEntity(fields: ['category', 'month'], message: 'Un budget existe déjà pour cette catégorie et ce mois.')]
class Budget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Plafond de dépenses pour le mois. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\Positive]
    private ?string $amount = null;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    #[Assert\NotBlank]
    #[Assert\Currency]
    private string $currency = 'EUR';

    /** Toujours le premier jour du mois (normalisé par le setter). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $month = null;

    #[ORM\ManyToOne(inversedBy: 'budgets')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Category $category = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function getMonth(): ?\DateTimeImmutable
    {
        return $this->month;
    }

    public function setMonth(\DateTimeImmutable $month): static
    {
        $this->month = $month->modify('first day of this month')->setTime(0, 0);

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
}
