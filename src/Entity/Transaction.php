<?php

namespace App\Entity;

use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Index(columns: ['date'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $label = null;

    /** Montant toujours positif ; le sens est donné par le type. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\Positive]
    private ?string $amount = null;

    #[ORM\Column(enumType: TransactionType::class)]
    #[Assert\NotNull]
    private ?TransactionType $type = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Category $category = null;

    /** La devise de la transaction est celle du compte. */
    #[ORM\ManyToOne(inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Account $account = null;

    /** Null = transaction ponctuelle. */
    #[ORM\Column(enumType: RecurrenceFrequency::class, nullable: true)]
    private ?RecurrenceFrequency $recurrence = null;

    /** Dernière date de récurrence ; null = sans fin. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recurrenceEndDate = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRecurrence(): ?RecurrenceFrequency
    {
        return $this->recurrence;
    }

    public function setRecurrence(?RecurrenceFrequency $recurrence): static
    {
        $this->recurrence = $recurrence;

        return $this;
    }

    public function getRecurrenceEndDate(): ?\DateTimeImmutable
    {
        return $this->recurrenceEndDate;
    }

    public function setRecurrenceEndDate(?\DateTimeImmutable $recurrenceEndDate): static
    {
        $this->recurrenceEndDate = $recurrenceEndDate;

        return $this;
    }

    #[Assert\Callback]
    public function validateRecurrence(ExecutionContextInterface $context): void
    {
        if (null === $this->recurrence && null !== $this->recurrenceEndDate) {
            $context->buildViolation('Une date de fin de récurrence nécessite une fréquence.')
                ->atPath('recurrenceEndDate')
                ->addViolation();
        }

        if ($this->recurrenceEndDate && $this->date && $this->recurrenceEndDate < $this->date) {
            $context->buildViolation('La fin de la récurrence ne peut pas précéder la date de la transaction.')
                ->atPath('recurrenceEndDate')
                ->addViolation();
        }
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

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

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
