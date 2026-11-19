<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\ResetPasswordRequestType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/reset-password')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Étape 1 : saisie de l'adresse e-mail. */
    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, MailerInterface $mailer, #[Autowire('%app.mailer_from%')] string $from): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(ResetPasswordRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->processSendingPasswordResetEmail($form->get('email')->getData(), $mailer, $from);
        }

        return $this->render('reset_password/request.html.twig', ['form' => $form]);
    }

    /** Étape 2 : confirmation, identique que le compte existe ou non. */
    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // Jeton factice si aucun n'a été généré : on ne révèle pas l'existence du compte.
        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $this->getTokenObjectFromSession() ?? $this->resetPasswordHelper->generateFakeResetToken(),
        ]);
    }

    /** Étape 3 : choix du nouveau mot de passe via le lien reçu par e-mail. */
    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, ?string $token = null): Response
    {
        if (null !== $token) {
            // Le jeton est retiré de l'URL (historique, en-tête Referer) et conservé en session.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('Aucun jeton de réinitialisation trouvé dans l\'URL ou en session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->addFlash('error', 'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le jeton est à usage unique.
            $this->resetPasswordHelper->removeResetRequest($token);

            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $this->entityManager->flush();
            $this->cleanSessionAfterReset();

            $this->addFlash('success', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', ['form' => $form]);
    }

    private function processSendingPasswordResetEmail(string $emailFormData, MailerInterface $mailer, string $from): RedirectResponse
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($emailFormData))]);

        // Même réponse que le compte existe ou non : pas d'énumération des comptes.
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Typiquement : demande trop rapprochée. On ne le révèle pas non plus.
            return $this->redirectToRoute('app_check_email');
        }

        $mailer->send((new TemplatedEmail())
            ->from(new Address($from, 'FINORA'))
            ->to((string) $user->getEmail())
            ->subject('Réinitialisation de votre mot de passe FINORA')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context(['resetToken' => $resetToken, 'displayName' => $user->getDisplayName()])
        );

        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_check_email');
    }
}
