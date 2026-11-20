<?php

namespace App\Entity;

use App\Repository\TransferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Virement d'un compte vers un autre du même utilisateur et de la même devise.
 *
 * Ce n'est ni un revenu ni une dépense : il déplace de l'argent entre deux comptes, sans changer le solde total ni les
 * totaux du mois. Seuls les soldes des deux comptes évoluent.
 */
#[ORM\Entity(repositoryClass: TransferRepository::class)]
#[ORM\Index(columns: ['date'])]
class Transfer implements OwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Choisissez le compte à débiter.')]
    private ?Account $fromAccount = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Choisissez le compte à créditer.')]
    private ?Account $toAccount = null;

    /** Montant toujours positif, dans la devise (commune) des deux comptes. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\Positive]
    private ?string $amount = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Un virement appartient au propriétaire du compte débité. */
    public function getOwner(): ?User
    {
        return $this->fromAccount?->getOwner();
    }

    public function getFromAccount(): ?Account
    {
        return $this->fromAccount;
    }

    public function setFromAccount(?Account $fromAccount): static
    {
        $this->fromAccount = $fromAccount;

        return $this;
    }

    public function getToAccount(): ?Account
    {
        return $this->toAccount;
    }

    public function setToAccount(?Account $toAccount): static
    {
        $this->toAccount = $toAccount;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
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

    /** Devise du virement : celle des deux comptes. */
    public function getCurrency(): ?string
    {
        return $this->fromAccount?->getCurrency();
    }

    #[Assert\Callback]
    public function validateAccounts(ExecutionContextInterface $context): void
    {
        if (null === $this->fromAccount || null === $this->toAccount) {
            return;
        }

        if ($this->fromAccount === $this->toAccount) {
            $context->buildViolation('Choisissez deux comptes différents.')->atPath('toAccount')->addViolation();

            return;
        }

        if ($this->fromAccount->getOwner() !== $this->toAccount->getOwner()) {
            $context->buildViolation('Les deux comptes doivent vous appartenir.')->atPath('toAccount')->addViolation();

            return;
        }

        if ($this->fromAccount->getCurrency() !== $this->toAccount->getCurrency()) {
            $context->buildViolation(sprintf(
                'Les deux comptes doivent avoir la même devise (%s et %s) : aucune conversion n\'est faite.',
                $this->fromAccount->getCurrency(),
                $this->toAccount->getCurrency(),
            ))->atPath('toAccount')->addViolation();
        }
    }
}
