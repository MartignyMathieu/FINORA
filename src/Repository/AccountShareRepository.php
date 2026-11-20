<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountShare;
use App\Entity\User;
use App\Enum\ShareStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountShare>
 */
class AccountShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountShare::class);
    }

    /** Partages d'un compte (invités et état), les plus anciens d'abord. @return list<AccountShare> */
    public function findForAccount(Account $account): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('i')
            ->join('s.invitee', 'i')
            ->where('s.account = :account')
            ->setParameter('account', $account)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Invitations reçues et pas encore traitées. @return list<AccountShare> */
    public function findPendingFor(User $invitee): array
    {
        return $this->forInvitee($invitee, ShareStatus::Pending);
    }

    /** Comptes partagés avec cet utilisateur (accès accepté). @return list<AccountShare> */
    public function findAcceptedFor(User $invitee): array
    {
        return $this->forInvitee($invitee, ShareStatus::Accepted);
    }

    /** Accès accepté de cet utilisateur à ce compte, ou null. */
    public function findAccepted(Account $account, User $user): ?AccountShare
    {
        return $this->findOneBy(['account' => $account, 'invitee' => $user, 'status' => ShareStatus::Accepted]);
    }

    public function countPendingFor(User $invitee): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.invitee = :user AND s.status = :status')
            ->setParameter('user', $invitee)
            ->setParameter('status', ShareStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Nombre de personnes ayant (ou ayant été invitées à avoir) accès à chacun de ces comptes : [identifiant du compte => nombre]. @param list<Account> $accounts @return array<int, int> */
    public function countByAccount(array $accounts): array
    {
        if ([] === $accounts) {
            return [];
        }
        $counts = [];
        foreach ($this->createQueryBuilder('s')
            ->select('IDENTITY(s.account) AS accountId, COUNT(s.id) AS total')
            ->where('s.account IN (:accounts)')
            ->setParameter('accounts', $accounts)
            ->groupBy('s.account')
            ->getQuery()
            ->getArrayResult() as $row) {
            $counts[(int) $row['accountId']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return list<AccountShare> */
    private function forInvitee(User $invitee, ShareStatus $status): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('a', 'o')
            ->join('s.account', 'a')
            ->join('a.owner', 'o')
            ->where('s.invitee = :user AND s.status = :status')
            ->setParameter('user', $invitee)
            ->setParameter('status', $status)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
