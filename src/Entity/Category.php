<?php

namespace App\Entity;

use App\Enum\Icon;
use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[UniqueEntity(fields: ['name'], message: 'Une catégorie porte déjà ce nom.')]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'La couleur doit être au format hexadécimal (#RRGGBB).')]
    private ?string $color = null;

    #[ORM\Column(enumType: Icon::class, nullable: true)]
    private ?Icon $icon = null;

    /** @var Collection<int, Transaction> */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'category')]
    private Collection $transactions;

    /** @var Collection<int, Budget> */
    #[ORM\OneToMany(targetEntity: Budget::class, mappedBy: 'category', orphanRemoval: true)]
    private Collection $budgets;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->budgets = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

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

    /** @return Collection<int, Transaction> */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    /** @return Collection<int, Budget> */
    public function getBudgets(): Collection
    {
        return $this->budgets;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
