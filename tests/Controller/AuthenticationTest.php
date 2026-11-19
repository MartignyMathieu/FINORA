<?php

namespace App\Tests\Controller;

use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuthenticationTest extends AbstractCrudTestCase
{
    /** Client sans session : visiteur anonyme. */
    private function anonymousClient(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->manager = static::getContainer()->get('doctrine')->getManager();
    }

    /** @return iterable<string, array{string}> */
    public static function protectedUrls(): iterable
    {
        foreach (['/', '/account', '/account/new', '/transaction', '/transaction/new', '/budget', '/budget/new', '/goal', '/goal/new', '/category', '/category/new', '/stats', '/settings', '/recurring', '/calendar', '/profile', '/export/transactions'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('protectedUrls')]
    public function testAnonymousVisitorIsRedirectedToLogin(string $url): void
    {
        $this->anonymousClient();

        $this->client->request('GET', $url);

        self::assertResponseRedirects('/login');
    }

    public function testLoginPageIsPublic(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
        self::assertSelectorTextContains('.auth-tagline', 'Maîtrisez votre argent.');
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'alice@example.test', '_password' => self::PASSWORD]);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour Alice');
    }

    public function testLoginWithWrongPasswordShowsAnError(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'alice@example.test', '_password' => 'mauvais']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error');
        self::assertInputValueSame('_username', 'alice@example.test');
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        $this->createUser('cible@example.test', 'Cible');
        $this->anonymousClient();

        for ($i = 0; $i < 6; ++$i) {
            $this->client->request('GET', '/login');
            $this->client->submitForm('Se connecter', ['_username' => 'cible@example.test', '_password' => 'mauvais-'.$i]);
        }

        // Une fois la limite atteinte, même le bon mot de passe est refusé.
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'cible@example.test', '_password' => self::PASSWORD]);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error');
    }

    public function testLoggedInUserIsRedirectedAwayFromLogin(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    public function testLogout(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/logout');
        self::assertResponseRedirects();

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testRegistrationCreatesAUserWithAHashedPassword(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/register');
        $this->client->submitForm('Commencer', [
            'registration[displayName]' => 'Bob',
            'registration[email]' => 'Bob@Example.TEST',
            'registration[plainPassword][first]' => 'un-mot-de-passe-solide',
            'registration[plainPassword][second]' => 'un-mot-de-passe-solide',
        ]);

        self::assertResponseRedirects('/login');

        $user = $this->manager->getRepository(User::class)->findOneBy(['email' => 'bob@example.test']);
        self::assertNotNull($user, 'L\'e-mail est enregistré en minuscules.');
        self::assertSame('Bob', $user->getDisplayName());
        self::assertNotSame('un-mot-de-passe-solide', $user->getPassword());
        self::assertStringStartsWith('$', (string) $user->getPassword());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testRegisteredUserCanLogIn(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/register');
        $this->client->submitForm('Commencer', [
            'registration[displayName]' => 'Bob',
            'registration[email]' => 'bob@example.test',
            'registration[plainPassword][first]' => 'un-mot-de-passe-solide',
            'registration[plainPassword][second]' => 'un-mot-de-passe-solide',
        ]);
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'bob@example.test', '_password' => 'un-mot-de-passe-solide']);

        self::assertResponseRedirects('/');
    }

    public function testRegistrationRejectsAnExistingEmail(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/register');
        $this->client->submitForm('Commencer', [
            'registration[displayName]' => 'Autre Alice',
            'registration[email]' => 'alice@example.test',
            'registration[plainPassword][first]' => 'un-mot-de-passe-solide',
            'registration[plainPassword][second]' => 'un-mot-de-passe-solide',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->manager->getRepository(User::class)->count([]));
    }

    public function testRegistrationRejectsAShortPassword(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/register');
        $this->client->submitForm('Commencer', [
            'registration[displayName]' => 'Bob',
            'registration[email]' => 'bob@example.test',
            'registration[plainPassword][first]' => 'court',
            'registration[plainPassword][second]' => 'court',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->manager->getRepository(User::class)->findOneBy(['email' => 'bob@example.test']));
    }

    public function testRegistrationRejectsMismatchedPasswords(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/register');
        $this->client->submitForm('Commencer', [
            'registration[displayName]' => 'Bob',
            'registration[email]' => 'bob@example.test',
            'registration[plainPassword][first]' => 'un-mot-de-passe-solide',
            'registration[plainPassword][second]' => 'un-autre-mot-de-passe',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegistrationRejectsAnInvalidEmail(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/register');
        $this->client->submitForm('Commencer', [
            'registration[displayName]' => 'Bob',
            'registration[email]' => 'pas-un-email',
            'registration[plainPassword][first]' => 'un-mot-de-passe-solide',
            'registration[plainPassword][second]' => 'un-mot-de-passe-solide',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegistrationCannotGrantRoles(): void
    {
        $this->anonymousClient();

        $this->client->request('GET', '/register');
        $form = $this->client->getCrawler()->selectButton('Commencer')->form([
            'registration[displayName]' => 'Mallory',
            'registration[email]' => 'mallory@example.test',
            'registration[plainPassword][first]' => 'un-mot-de-passe-solide',
            'registration[plainPassword][second]' => 'un-mot-de-passe-solide',
        ]);
        $values = $form->getPhpValues();
        $values['registration']['roles'] = ['ROLE_ADMIN'];
        $this->client->request('POST', '/register', $values);

        // Le formulaire refuse les champs en trop : aucun compte n'est créé, a fortiori pas avec un rôle admin.
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->manager->getRepository(User::class)->findOneBy(['email' => 'mallory@example.test']));
    }
}
