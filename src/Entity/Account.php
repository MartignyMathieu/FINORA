<?php

namespace App\Entity;

use App\Enum\AccountType;
use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    #[ORM\Column(enumType: AccountType::class, options: ['default' => 'checking'])]
    #[Assert\NotNull]
    private AccountType $type = AccountType::Checking;

    /** Code ISO 4217 (EUR, USD, ...). Toutes les transactions du compte sont dans cette devise. */
    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    #[Assert\NotBlank]
    #[Assert\Currency]
    private string $currency = 'EUR';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Assert\NotNull]
    private string $initialBalance = '0.00';

    /** @var Collection<int, Transaction> */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'account')]
    private Collection $transactions;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
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

    public function getType(): AccountType
    {
        return $this->type;
    }

    public function setType(AccountType $type): static
    {
        $this->type = $type;

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

    public function getInitialBalance(): string
    {
        return $this->initialBalance;
    }

    public function setInitialBalance(string $initialBalance): static
    {
        $this->initialBalance = $initialBalance;

        return $this;
    }

    /** @return Collection<int, Transaction> */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
