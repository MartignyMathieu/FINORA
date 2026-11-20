<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\AccountShare;
use App\Entity\User;
use App\Enum\ShareRole;
use App\Form\ShareInviteType;
use App\Repository\AccountShareRepository;
use App\Repository\UserRepository;
use App\Security\OwnerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Partage d'un compte, côté propriétaire : inviter, changer les droits, retirer l'accès. */
#[Route('/account')]
final class AccountShareController extends AbstractController
{
    #[IsGranted(OwnerVoter::MANAGE, 'account')]
    #[Route('/{id}/share', name: 'app_account_share', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        Account $account,
        AccountShareRepository $shares,
        UserRepository $users,
        EntityManagerInterface $em,
        RateLimiterFactoryInterface $shareInvitationLimiter,
        #[CurrentUser] User $user,
    ): Response {
        $form = $this->createForm(ShareInviteType::class, ['role' => ShareRole::Viewer]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $form->get('email')->getData()));
            $invitee = $users->findOneBy(['email' => $email]);

            if (!$shareInvitationLimiter->create((string) $user->getId())->consume()->isAccepted()) {
                $form->get('email')->addError(new FormError('Trop d\'invitations envoyées : réessayez dans une heure.'));
            } elseif (null === $invitee) {
                $form->get('email')->addError(new FormError('Aucun compte FINORA n\'utilise cette adresse. Demandez à la personne de s\'inscrire d\'abord.'));
            } elseif ($invitee->getId() === $user->getId()) {
                $form->get('email')->addError(new FormError('Ce compte vous appartient déjà.'));
            } elseif (null !== $shares->findOneBy(['account' => $account, 'invitee' => $invitee])) {
                $form->get('email')->addError(new FormError('Cette personne a déjà été invitée à partager ce compte.'));
            } else {
                $share = (new AccountShare())->setAccount($account)->setInvitee($invitee)->setRole($form->get('role')->getData());
                $em->persist($share);
                $em->flush();
                $this->addFlash('success', sprintf('Invitation envoyée à %s. Elle la verra dans Comptes → Comptes partagés.', $invitee->getDisplayName()));

                return $this->redirectToRoute('app_account_share', ['id' => $account->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('account/share.html.twig', [
            'account' => $account,
            'shares' => $shares->findForAccount($account),
            'form' => $form,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[IsGranted(OwnerVoter::MANAGE, 'share')]
    #[Route('/share/{id}/role', name: 'app_account_share_role', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeRole(Request $request, AccountShare $share, EntityManagerInterface $em): Response
    {
        $role = ShareRole::tryFrom($request->getPayload()->getString('role'));
        if (!$this->isCsrfTokenValid('share-role'.$share->getId(), $request->getPayload()->getString('_token')) || null === $role) {
            $this->addFlash('error', 'Modification impossible : rechargez la page et réessayez.');
        } else {
            $share->setRole($role);
            $em->flush();
            $this->addFlash('success', sprintf('Droits de %s : %s.', $share->getInvitee()->getDisplayName(), mb_strtolower($role->label())));
        }

        return $this->redirectToRoute('app_account_share', ['id' => $share->getAccount()->getId()], Response::HTTP_SEE_OTHER);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'share')]
    #[Route('/share/{id}/remove', name: 'app_account_share_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(Request $request, AccountShare $share, EntityManagerInterface $em): Response
    {
        $accountId = $share->getAccount()->getId();
        if ($this->isCsrfTokenValid('share-remove'.$share->getId(), $request->getPayload()->getString('_token'))) {
            $name = $share->getInvitee()->getDisplayName();
            $em->remove($share);
            $em->flush();
            $this->addFlash('success', sprintf('%s n\'a plus accès à ce compte.', $name));
        }

        return $this->redirectToRoute('app_account_share', ['id' => $accountId], Response::HTTP_SEE_OTHER);
    }
}
