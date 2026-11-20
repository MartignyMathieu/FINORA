<?php

namespace App\Controller;

use App\Entity\Account;
use App\Form\AccountType;
use App\Repository\AccountShareRepository;
use App\Repository\TransferRepository;
use App\Service\FinanceStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\OwnerVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\User;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/account')]
final class AccountController extends AbstractController
{
    #[Route(name: 'app_account_index', methods: ['GET'])]
    public function index(FinanceStats $stats, AccountShareRepository $shares): Response
    {
        $rows = $stats->accountBalances();

        return $this->render('account/index.html.twig', [
            'rows' => $rows,
            // Nombre de personnes invitées sur chaque compte (étiquette « Partagé »).
            'shareCounts' => $shares->countByAccount(array_map(static fn (array $row): Account => $row['account'], $rows)),
        ]);
    }

    #[Route('/new', name: 'app_account_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, #[CurrentUser] User $user): Response
    {
        $account = (new Account())->setOwner($user);
        $form = $this->createForm(AccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($account);
            $entityManager->flush();

            return $this->redirectToRoute('app_account_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('account/new.html.twig', [
            'account' => $account,
            'form' => $form,
        ]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'account')]
    #[Route('/{id}', name: 'app_account_show', methods: ['GET'])]
    public function show(Account $account): Response
    {
        return $this->render('account/show.html.twig', [
            'account' => $account,
        ]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'account')]
    #[Route('/{id}/edit', name: 'app_account_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Account $account, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(AccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_account_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('account/edit.html.twig', [
            'account' => $account,
            'form' => $form,
        ]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'account')]
    #[Route('/{id}', name: 'app_account_delete', methods: ['POST'])]
    public function delete(Request $request, Account $account, EntityManagerInterface $entityManager, TransferRepository $transfers): Response
    {
        if ($this->isCsrfTokenValid('delete'.$account->getId(), $request->getPayload()->getString('_token'))) {
            if (!$account->getTransactions()->isEmpty() || $transfers->countForAccount($account) > 0) {
                $this->addFlash('error', 'Ce compte contient des transactions ou des virements et ne peut pas être supprimé.');

                return $this->redirectToRoute('app_account_show', ['id' => $account->getId()], Response::HTTP_SEE_OTHER);
            }

            $entityManager->remove($account);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_account_index', [], Response::HTTP_SEE_OTHER);
    }
}
