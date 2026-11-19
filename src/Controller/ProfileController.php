<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangeEmailType;
use App\Form\DeleteAccountType;
use App\Form\ProfileType;
use App\Form\UpdatePasswordType;
use App\Service\FinanceStats;
use App\Service\UserDataPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/profile')]
final class ProfileController extends AbstractController
{
    /**
     * Quatre formulaires indépendants sur une même page : chacun n'est traité que s'il a été soumis.
     * Un formulaire invalide ne modifie rien (l'entité est rechargée pour ne pas afficher des valeurs non enregistrées).
     */
    #[Route(name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        Security $security,
        FinanceStats $stats,
    ): Response {
        $profileForm = $this->createForm(ProfileType::class, $user)->handleRequest($request);
        $emailForm = $this->createForm(ChangeEmailType::class, $user)->handleRequest($request);
        $passwordForm = $this->createForm(UpdatePasswordType::class)->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Votre prénom a été mis à jour.');

            return $this->redirectToRoute('app_profile', [], Response::HTTP_SEE_OTHER);
        }

        if ($emailForm->isSubmitted() && $emailForm->isValid()) {
            $entityManager->flush();
            // L'identifiant de connexion a changé : on rouvre la session avec le nouvel utilisateur.
            $security->login($user);
            $this->addFlash('success', 'Votre adresse e-mail a été mise à jour.');

            return $this->redirectToRoute('app_profile', [], Response::HTTP_SEE_OTHER);
        }

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $user->setPassword($passwordHasher->hashPassword($user, $passwordForm->get('plainPassword')->getData()));
            $entityManager->flush();
            // Les autres sessions de l'utilisateur sont fermées (le mot de passe fait partie de la session) ;
            // celle-ci est rouverte.
            $security->login($user);
            $this->addFlash('success', 'Votre mot de passe a été modifié. Vos autres sessions ont été déconnectées.');

            return $this->redirectToRoute('app_profile', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderProfile($user, $stats, $entityManager, $profileForm, $emailForm, $passwordForm, $this->createForm(DeleteAccountType::class));
    }

    /** Suppression définitive du compte et de toutes ses données. */
    #[Route('/delete', name: 'app_profile_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $entityManager,
        UserDataPurger $purger,
        Security $security,
        FinanceStats $stats,
    ): Response {
        $form = $this->createForm(DeleteAccountType::class)->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderProfile(
                $user,
                $stats,
                $entityManager,
                $this->createForm(ProfileType::class, $user),
                $this->createForm(ChangeEmailType::class, $user),
                $this->createForm(UpdatePasswordType::class),
                $form,
            );
        }

        // Déconnexion d'abord (le jeton référence l'utilisateur), puis suppression de tout.
        $security->logout(false);
        $purger->purge($user);

        $this->addFlash('success', 'Votre compte et toutes vos données ont été supprimés.');

        return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
    }

    private function renderProfile(
        User $user,
        FinanceStats $stats,
        EntityManagerInterface $entityManager,
        FormInterface $profileForm,
        FormInterface $emailForm,
        FormInterface $passwordForm,
        FormInterface $deleteForm,
    ): Response {
        $isRejected = static fn (FormInterface $form): bool => $form->isSubmitted() && !$form->isValid();

        // Un formulaire mappé soumis mais invalide a pu modifier l'entité en mémoire : on revient à l'état
        // enregistré (le menu affiche le prénom et l'e-mail du compte, pas les valeurs refusées).
        if ($isRejected($profileForm) || $isRejected($emailForm)) {
            $entityManager->refresh($user);
        }

        $rejected = $isRejected($profileForm) || $isRejected($emailForm) || $isRejected($passwordForm) || $isRejected($deleteForm);

        return $this->render('profile/index.html.twig', [
            'profileForm' => $profileForm,
            'emailForm' => $emailForm,
            'passwordForm' => $passwordForm,
            'deleteForm' => $deleteForm,
            'counts' => $stats->counts(),
        ], new Response(status: $rejected ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
