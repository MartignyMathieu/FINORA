<?php

namespace App\Controller;

use App\Entity\Goal;
use App\Entity\GoalContribution;
use App\Form\GoalContributionType;
use App\Security\OwnerVoter;
use App\Service\GoalSavings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Versements et retraits sur un objectif d'épargne, avec leur historique. */
#[Route('/goal/{goal}/contribution', requirements: ['goal' => '\d+'])]
final class GoalContributionController extends AbstractController
{
    #[IsGranted(OwnerVoter::MANAGE, 'goal')]
    #[Route('/new', name: 'app_goal_contribution_new', methods: ['GET', 'POST'])]
    public function new(Request $request, Goal $goal, GoalSavings $savings, ClockInterface $clock): Response
    {
        $form = $this->createForm(GoalContributionType::class, [
            'kind' => GoalContributionType::DEPOSIT,
            'date' => \DateTimeImmutable::createFromInterface($clock->now())->setTime(0, 0),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $amount = (float) $data['amount'] * (GoalContributionType::WITHDRAWAL === $data['kind'] ? -1 : 1);
            $wasReached = $goal->isReached();

            try {
                $savings->record($goal, $amount, $data['date'], $data['note']);
            } catch (\DomainException $e) {
                $form->get('amount')->addError(new FormError($e->getMessage()));
            }

            if ($form->isValid()) {
                $this->addFlash('success', $amount < 0 ? 'Le retrait a été enregistré.' : 'Le versement a été enregistré.');
                if (!$wasReached && $goal->isReached()) {
                    $this->addFlash('success', sprintf('Objectif « %s » atteint, bravo !', $goal->getName()));
                }

                return $this->redirectToRoute('app_goal_show', ['id' => $goal->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('goal/contribution_new.html.twig', ['goal' => $goal, 'form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[IsGranted(OwnerVoter::MANAGE, 'contribution')]
    #[Route('/{contribution}/delete', name: 'app_goal_contribution_delete', requirements: ['contribution' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Goal $goal, GoalContribution $contribution, GoalSavings $savings): Response
    {
        // La ligne doit appartenir à l'objectif de l'adresse (pas d'objectif « prête-nom »).
        if ($contribution->getGoal() !== $goal) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete-contribution'.$contribution->getId(), $request->getPayload()->getString('_token'))) {
            $savings->cancel($contribution);
            $this->addFlash('success', 'La ligne a été supprimée : l\'épargne de l\'objectif est rétablie.');
        }

        return $this->redirectToRoute('app_goal_show', ['id' => $goal->getId()], Response::HTTP_SEE_OTHER);
    }
}
