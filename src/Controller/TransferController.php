<?php

namespace App\Controller;

use App\Entity\Transfer;
use App\Entity\User;
use App\Form\TransferType;
use App\Repository\TransferRepository;
use App\Security\OwnerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Virements entre deux comptes de l'utilisateur (même devise) : ni revenu ni dépense. */
#[Route('/transfer')]
final class TransferController extends AbstractController
{
    #[Route(name: 'app_transfer_index', methods: ['GET'])]
    public function index(TransferRepository $transfers, #[CurrentUser] User $user): Response
    {
        return $this->render('transfer/index.html.twig', ['transfers' => $transfers->findForOwner($user)]);
    }

    #[Route('/new', name: 'app_transfer_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $transfer = (new Transfer())->setDate(\DateTimeImmutable::createFromInterface($clock->now())->setTime(0, 0));
        $form = $this->createForm(TransferType::class, $transfer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($transfer);
            $entityManager->flush();
            $this->addFlash('success', 'Le virement a été enregistré.');

            return $this->redirectToRoute('app_transfer_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('transfer/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[IsGranted(OwnerVoter::MANAGE, 'transfer')]
    #[Route('/{id}/edit', name: 'app_transfer_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Transfer $transfer, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TransferType::class, $transfer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Le virement a été modifié.');

            return $this->redirectToRoute('app_transfer_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('transfer/edit.html.twig', ['transfer' => $transfer, 'form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[IsGranted(OwnerVoter::MANAGE, 'transfer')]
    #[Route('/{id}', name: 'app_transfer_delete', methods: ['POST'])]
    public function delete(Request $request, Transfer $transfer, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$transfer->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($transfer);
            $entityManager->flush();
            $this->addFlash('success', 'Le virement a été supprimé : les soldes des deux comptes sont rétablis.');
        }

        return $this->redirectToRoute('app_transfer_index', [], Response::HTTP_SEE_OTHER);
    }
}
