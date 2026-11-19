<?php

namespace App\Tests\Controller;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Symfony\Component\Mime\Email;

final class ResetPasswordTest extends AbstractCrudTestCase
{
    private const NEW_PASSWORD = 'un-nouveau-mot-de-passe';

    protected function setUp(): void
    {
        parent::setUp();
        // Visiteur anonyme (setUp a connecté Alice).
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->manager = static::getContainer()->get('doctrine')->getManager();
    }

    private function requestResetFor(string $email): void
    {
        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Envoyer le lien', ['reset_password_request[email]' => $email]);
    }

    /** Extrait le lien de réinitialisation de l'e-mail envoyé. */
    private function resetLinkFromEmail(int $index = 0): string
    {
        $email = $this->getMailerMessage($index);
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(1, preg_match('#href="([^"]*/reset-password/reset/[^"]+)"#', (string) $email->getHtmlBody(), $matches));

        return html_entity_decode($matches[1]);
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => $email, '_password' => $password]);
    }

    public function testRequestPageIsPublicAndLinkedFromLogin(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertCount(1, $crawler->filter('a[href="/reset-password"]'));

        $this->client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type="email"]');
    }

    public function testRequestForAnExistingAccountSendsOneEmail(): void
    {
        $this->requestResetFor('alice@example.test');

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(1);

        $email = $this->getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'alice@example.test');
        self::assertEmailAddressContains($email, 'From', 'no-reply@finora.local');
        self::assertEmailHtmlBodyContains($email, 'Bonjour Alice');
        self::assertEmailHtmlBodyContains($email, '/reset-password/reset/');
        self::assertEmailHeaderSame($email, 'subject', 'Réinitialisation de votre mot de passe FINORA');
    }

    public function testRequestForAnUnknownAccountLooksIdenticalAndSendsNothing(): void
    {
        $this->requestResetFor('personne@example.test');

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(0);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auth-notice', 'Si un compte correspond');
    }

    public function testRequestIsCaseInsensitive(): void
    {
        $this->requestResetFor('  Alice@EXAMPLE.test ');

        self::assertQueuedEmailCount(1);
    }

    public function testRepeatedRequestsAreThrottled(): void
    {
        $this->requestResetFor('alice@example.test');
        self::assertQueuedEmailCount(1);

        // Les assertions d'e-mails portent sur la dernière requête : la seconde n'en envoie aucun,
        // et répond exactement comme la première.
        $this->requestResetFor('alice@example.test');
        self::assertQueuedEmailCount(0);
        self::assertResponseRedirects('/reset-password/check-email');
    }

    public function testRequestRejectsAnInvalidEmail(): void
    {
        $this->requestResetFor('pas-un-email');

        self::assertResponseStatusCodeSame(422);
        self::assertQueuedEmailCount(0);
    }

    public function testFullFlowChangesThePassword(): void
    {
        $this->requestResetFor('alice@example.test');
        $link = $this->resetLinkFromEmail();

        // Le jeton est retiré de l'URL et conservé en session.
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/reset-password/reset');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Enregistrer le mot de passe', [
            'change_password[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password[plainPassword][second]' => self::NEW_PASSWORD,
        ]);
        self::assertResponseRedirects('/login');

        // L'ancien mot de passe ne fonctionne plus, le nouveau oui.
        $this->login('alice@example.test', self::PASSWORD);
        self::assertResponseRedirects('/login');
        $this->login('alice@example.test', self::NEW_PASSWORD);
        self::assertResponseRedirects('/');
    }

    public function testTheResetLinkCanOnlyBeUsedOnce(): void
    {
        $this->requestResetFor('alice@example.test');
        $link = $this->resetLinkFromEmail();

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        $this->client->submitForm('Enregistrer le mot de passe', [
            'change_password[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password[plainPassword][second]' => self::NEW_PASSWORD,
        ]);
        self::assertSame(0, $this->manager->getRepository(ResetPasswordRequest::class)->count([]));

        // Second usage du même lien.
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password');
    }

    public function testAnInvalidTokenIsRefused(): void
    {
        $this->client->request('GET', '/reset-password/reset/nimporte-quoi');
        self::assertResponseRedirects('/reset-password/reset');
        $this->client->followRedirect();

        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error');
    }

    public function testResetPageWithoutATokenIsNotFound(): void
    {
        $this->client->request('GET', '/reset-password/reset');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNewPasswordIsValidated(): void
    {
        $this->requestResetFor('alice@example.test');
        $this->client->request('GET', $this->resetLinkFromEmail());
        $this->client->followRedirect();

        $this->client->submitForm('Enregistrer le mot de passe', [
            'change_password[plainPassword][first]' => 'court',
            'change_password[plainPassword][second]' => 'court',
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->client->submitForm('Enregistrer le mot de passe', [
            'change_password[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password[plainPassword][second]' => 'autre-mot-de-passe',
        ]);
        self::assertResponseStatusCodeSame(422);

        // Rien n'a changé : l'ancien mot de passe fonctionne toujours.
        $this->login('alice@example.test', self::PASSWORD);
        self::assertResponseRedirects('/');
    }

    public function testExpiredTokenIsRefused(): void
    {
        $this->requestResetFor('alice@example.test');
        $link = $this->resetLinkFromEmail();

        $this->manager->createQuery('UPDATE '.ResetPasswordRequest::class.' r SET r.expiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 hour'), 'datetime_immutable')
            ->execute();

        $this->client->request('GET', $link);
        $this->client->followRedirect();

        self::assertResponseRedirects('/reset-password');
    }

    public function testChangingThePasswordEndsExistingSessions(): void
    {
        // Session ouverte ailleurs (un autre navigateur).
        static::ensureKernelShutdown();
        $other = static::createClient();
        $other->loginUser($this->manager->getRepository(User::class)->findOneBy(['email' => 'alice@example.test']));
        $other->request('GET', '/');
        self::assertSame(200, $other->getResponse()->getStatusCode());

        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->manager = static::getContainer()->get('doctrine')->getManager();

        $this->requestResetFor('alice@example.test');
        $this->client->request('GET', $this->resetLinkFromEmail());
        $this->client->followRedirect();
        $this->client->submitForm('Enregistrer le mot de passe', [
            'change_password[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password[plainPassword][second]' => self::NEW_PASSWORD,
        ]);

        $other->request('GET', '/');
        self::assertTrue($other->getResponse()->isRedirect(), 'L\'ancienne session doit être déconnectée.');
        self::assertSame('/login', parse_url((string) $other->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }
}
