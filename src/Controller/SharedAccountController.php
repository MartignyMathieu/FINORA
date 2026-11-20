<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\AccountShare;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\ShareStatus;
use App\Form\SharedTransactionType;
use App\Repository\AccountShareRepository;
use App\Repository\TransactionRepository;
use App\Security\AccountAccessVoter;
use App\Service\AccountBalance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Comptes partagés avec l'utilisateur, côté personne invitée : invitations à accepter, comptes accessibles, et — selon les droits —
 * consultation et saisie de transactions. Ces comptes n'entrent jamais dans les totaux de l'invité (c'est l'argent du propriétaire).
 */
#[Route('/shared')]
final class SharedAccountController extends AbstractController
{
    #[Route(name: 'app_shared_index', methods: ['GET'])]
    public function index(AccountShareRepository $shares, AccountBalance $balances, #[CurrentUser] User $user): Response
    {
        $accepted = $shares->findAcceptedFor($user);

        return $this->render('shared/index.html.twig', [
            'pending' => $shares->findPendingFor($user),
            'accepted' => array_map(static fn (AccountShare $share): array => ['share' => $share, 'balance' => $balances->of($share->getAccount())], $accepted),
        ]);
    }

    #[Route('/invitation/{id}/accept', name: 'app_shared_accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(Request $request, AccountShare $share, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $this->assertInvitee($share, $user);
        if ($this->isCsrfTokenValid('share-accept'.$share->getId(), $request->getPayload()->getString('_token')) && $share->isPending()) {
            $share->setStatus(ShareStatus::Accepted);
            $em->flush();
            $this->addFlash('success', sprintf('Vous avez maintenant accès au compte « %s » de %s.', $share->getAccount()->getName(), $share->getAccount()->getOwner()->getDisplayName()));
        }

        return $this->redirectToRoute('app_shared_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Refuser une invitation, ou quitter un compte partagé : dans les deux cas, le partage est supprimé. */
    #[Route('/invitation/{id}/decline', name: 'app_shared_decline', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decline(Request $request, AccountShare $share, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $this->assertInvitee($share, $user);
        if ($this->isCsrfTokenValid('share-decline'.$share->getId(), $request->getPayload()->getString('_token'))) {
            $wasPending = $share->isPending();
            $name = $share->getAccount()->getName();
            $em->remove($share);
            $em->flush();
            $this->addFlash('success', $wasPending ? 'Invitation refusée.' : sprintf('Vous n\'avez plus accès au compte « %s ».', $name));
        }

        return $this->redirectToRoute('app_shared_index', [], Response::HTTP_SEE_OTHER);
    }

    #[IsGranted(AccountAccessVoter::VIEW, 'account')]
    #[Route('/account/{id}', name: 'app_shared_account', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Account $account, AccountShareRepository $shares, TransactionRepository $transactions, AccountBalance $balance, #[CurrentUser] User $user): Response
    {
        $share = $shares->findAccepted($account, $user);

        return $this->render('shared/show.html.twig', [
            'account' => $account,
            'share' => $share,
            'canEdit' => $this->isGranted(AccountAccessVoter::EDIT, $account),
            'balance' => $balance->of($account),
            'transactions' => $transactions->findLatestForAccount($account, 300),
        ]);
    }

    #[IsGranted(AccountAccessVoter::EDIT, 'account')]
    #[Route('/account/{id}/transaction/new', name: 'app_shared_transaction_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function newTransaction(Request $request, Account $account, EntityManagerInterface $em, ClockInterface $clock, #[CurrentUser] User $user): Response
    {
        $transaction = (new Transaction())
            ->setAccount($account)
            ->setDate(\DateTimeImmutable::createFromInterface($clock->now())->setTime(0, 0))
            // Le propriétaire reconnaît ce qui a été saisi par quelqu'un d'autre.
            ->setCreatedBy($account->getOwner()?->getId() === $user->getId() ? null : $user);
        $form = $this->createForm(SharedTransactionType::class, $transaction, ['account_owner' => $account->getOwner()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($transaction);
            $em->flush();
            $this->addFlash('success', 'La transaction a été enregistrée.');

            return $this->redirectToRoute('app_shared_account', ['id' => $account->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('shared/transaction_form.html.twig', ['account' => $account, 'form' => $form, 'transaction' => null], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[IsGranted(AccountAccessVoter::EDIT, 'transaction')]
    #[Route('/transaction/{id}/edit', name: 'app_shared_transaction_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editTransaction(Request $request, Transaction $transaction, EntityManagerInterface $em): Response
    {
        $account = $transaction->getAccount();
        $form = $this->createForm(SharedTransactionType::class, $transaction, ['account_owner' => $account->getOwner()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'La transaction a été modifiée.');

            return $this->redirectToRoute('app_shared_account', ['id' => $account->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('shared/transaction_form.html.twig', ['account' => $account, 'form' => $form, 'transaction' => $transaction], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[IsGranted(AccountAccessVoter::EDIT, 'transaction')]
    #[Route('/transaction/{id}/delete', name: 'app_shared_transaction_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteTransaction(Request $request, Transaction $transaction, EntityManagerInterface $em): Response
    {
        $accountId = $transaction->getAccount()->getId();
        if ($this->isCsrfTokenValid('shared-delete'.$transaction->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($transaction);
            $em->flush();
            $this->addFlash('success', 'La transaction a été supprimée.');
        }

        return $this->redirectToRoute('app_shared_account', ['id' => $accountId], Response::HTTP_SEE_OTHER);
    }

    /** Une invitation n'est lisible, acceptable ou refusable que par la personne invitée. */
    private function assertInvitee(AccountShare $share, User $user): void
    {
        if ($share->getInvitee()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }
    }
}
