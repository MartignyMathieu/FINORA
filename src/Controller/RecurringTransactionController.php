<?php

namespace App\Controller;

use App\Entity\RecurringTransaction;
use App\Form\RecurringTransactionType;
use App\Repository\RecurringTransactionRepository;
use App\Security\OwnerVoter;
use App\Service\FinanceStats;
use App\Service\RecurringTransactionGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\User;

#[Route('/recurring')]
final class RecurringTransactionController extends AbstractController
{
    #[Route(name: 'app_recurring_index', methods: ['GET'])]
    public function index(RecurringTransactionRepository $repository, FinanceStats $stats, #[CurrentUser] User $user): Response
    {
        return $this->render('recurring/index.html.twig', [
            'rules' => $repository->findByOwner($user),
            'monthly' => $stats->recurringMonthly(),
            'currency' => $stats->getCurrency(),
        ]);
    }

    #[Route('/new', name: 'app_recurring_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, RecurringTransactionGenerator $generator): Response
    {
        $recurring = new RecurringTransaction();
        $form = $this->createForm(RecurringTransactionType::class, $recurring);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($recurring);
            $entityManager->flush();
            $this->flashGenerated($generator->generateFor($recurring));

            return $this->redirectToRoute('app_recurring_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('recurring/new.html.twig', ['form' => $form]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'recurring')]
    #[Route('/{id}/edit', name: 'app_recurring_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, RecurringTransaction $recurring, EntityManagerInterface $entityManager, RecurringTransactionGenerator $generator): Response
    {
        $form = $this->createForm(RecurringTransactionType::class, $recurring);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->flashGenerated($generator->generateFor($recurring));

            return $this->redirectToRoute('app_recurring_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('recurring/edit.html.twig', ['recurring' => $recurring, 'form' => $form]);
    }

    /** Met la règle en pause ou la reprend (les échéances manquées pendant la pause sont alors rattrapées). */
    #[IsGranted(OwnerVoter::MANAGE, 'recurring')]
    #[Route('/{id}/toggle', name: 'app_recurring_toggle', methods: ['POST'])]
    public function toggle(Request $request, RecurringTransaction $recurring, EntityManagerInterface $entityManager, RecurringTransactionGenerator $generator): Response
    {
        if ($this->isCsrfTokenValid('toggle'.$recurring->getId(), $request->getPayload()->getString('_token'))) {
            $recurring->setActive(!$recurring->isActive());
            $entityManager->flush();

            if ($recurring->isActive()) {
                $this->flashGenerated($generator->generateFor($recurring));
            }
        }

        return $this->redirectToRoute('app_recurring_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Supprime la règle ; les transactions déjà générées sont conservées. */
    #[IsGranted(OwnerVoter::MANAGE, 'recurring')]
    #[Route('/{id}', name: 'app_recurring_delete', methods: ['POST'])]
    public function delete(Request $request, RecurringTransaction $recurring, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$recurring->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($recurring);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_recurring_index', [], Response::HTTP_SEE_OTHER);
    }

    private function flashGenerated(int $count): void
    {
        if ($count > 0) {
            $this->addFlash('success', 1 === $count
                ? '1 transaction a été générée.'
                : sprintf('%d transactions ont été générées.', $count));
        }
    }
}
