<?php

namespace App\Entity;

use App\Enum\ShareRole;
use App\Enum\ShareStatus;
use App\Repository\AccountShareRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Partage d'un compte avec une autre personne : une invitation (en attente), puis un accès (acceptée) avec un niveau de droits.
 *
 * Le propriétaire reste celui du compte ; la personne invitée voit le compte dans « Comptes partagés avec moi », sans qu'il compte dans
 * ses totaux. Supprimer le compte ou l'une des deux personnes supprime le partage.
 */
#[ORM\Entity(repositoryClass: AccountShareRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_account_share', columns: ['account_id', 'invitee_id'])]
class AccountShare implements OwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $invitee = null;

    #[ORM\Column(enumType: ShareRole::class)]
    private ShareRole $role = ShareRole::Viewer;

    #[ORM\Column(enumType: ShareStatus::class)]
    private ShareStatus $status = ShareStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Propriétaire du compte partagé (c'est lui qui gère le partage). */
    public function getOwner(): ?User
    {
        return $this->account?->getOwner();
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getInvitee(): ?User
    {
        return $this->invitee;
    }

    public function setInvitee(User $invitee): static
    {
        $this->invitee = $invitee;

        return $this;
    }

    public function getRole(): ShareRole
    {
        return $this->role;
    }

    public function setRole(ShareRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): ShareStatus
    {
        return $this->status;
    }

    public function setStatus(ShareStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isAccepted(): bool
    {
        return ShareStatus::Accepted === $this->status;
    }

    public function isPending(): bool
    {
        return ShareStatus::Pending === $this->status;
    }

    public function canEdit(): bool
    {
        return $this->isAccepted() && ShareRole::Editor === $this->role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
