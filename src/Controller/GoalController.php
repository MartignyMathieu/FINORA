<?php

namespace App\Controller;

use App\Entity\Goal;
use App\Form\GoalType;
use App\Repository\GoalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\OwnerVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\User;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/goal')]
final class GoalController extends AbstractController
{
    #[Route(name: 'app_goal_index', methods: ['GET'])]
    public function index(GoalRepository $goalRepository, #[CurrentUser] User $user): Response
    {
        return $this->render('goal/index.html.twig', [
            'goals' => $goalRepository->findAllByDeadline($user),
        ]);
    }

    #[Route('/new', name: 'app_goal_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, #[CurrentUser] User $user): Response
    {
        $goal = (new Goal())->setOwner($user);
        $form = $this->createForm(GoalType::class, $goal);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($goal);
            $entityManager->flush();

            return $this->redirectToRoute('app_goal_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('goal/new.html.twig', [
            'goal' => $goal,
            'form' => $form,
        ]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'goal')]
    #[Route('/{id}', name: 'app_goal_show', methods: ['GET'])]
    public function show(Goal $goal): Response
    {
        return $this->render('goal/show.html.twig', [
            'goal' => $goal,
        ]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'goal')]
    #[Route('/{id}/edit', name: 'app_goal_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Goal $goal, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(GoalType::class, $goal);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_goal_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('goal/edit.html.twig', [
            'goal' => $goal,
            'form' => $form,
        ]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'goal')]
    #[Route('/{id}', name: 'app_goal_delete', methods: ['POST'])]
    public function delete(Request $request, Goal $goal, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$goal->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($goal);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_goal_index', [], Response::HTTP_SEE_OTHER);
    }
}
