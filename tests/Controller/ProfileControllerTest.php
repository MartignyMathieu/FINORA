<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\RecurringTransaction;
use App\Entity\ResetPasswordRequest;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Avatar;
use App\Enum\TransactionType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileControllerTest extends AbstractCrudTestCase
{
    private const NEW_PASSWORD = 'un-nouveau-mot-de-passe';

    private function reload(): User
    {
        $this->manager->clear();

        return $this->manager->getRepository(User::class)->findOneBy(['id' => $this->user->getId()]);
    }

    private function passwordIsValid(User $user, string $plain): bool
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $plain);
    }

    /** Remplit un jeu complet de données pour un utilisateur. */
    private function fillWithData(User $owner, string $suffix): void
    {
        $account = $this->createAccount('Compte '.$suffix, 'EUR', $owner);
        $category = $this->createCategory('Catégorie '.$suffix, null, $owner);
        $this->createTransaction($account, $category, 'Achat '.$suffix);
        $this->createRecurring($account, $category, label: 'Règle '.$suffix);
        $this->createBudget($category);
        $this->createGoal('Objectif '.$suffix, owner: $owner);
    }

    public function testThePageShowsTheIdentityAndTheCountsOfOwnData(): void
    {
        $this->fillWithData($this->user, 'Alice');
        $this->createAccount('Autre compte d\'Alice');
        $bob = $this->createUser('bob@example.test', 'Bob');
        $this->fillWithData($bob, 'Bob');

        $crawler = $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Alice');
        self::assertSelectorTextContains('.page-head', 'alice@example.test');
        $counts = $crawler->filter('.counts li')->each(static fn ($li) => [trim($li->filter('span')->text()), (int) $li->filter('strong')->text()]);
        self::assertSame([['Comptes', 2], ['Transactions', 1], ['Récurrences', 1], ['Budgets', 1], ['Objectifs', 1], ['Catégories', 1]], $counts);
    }

    public function testTheNavigationLinksToTheProfile(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertGreaterThan(0, $crawler->filter('.sidebar-user a[href="/profile"]')->count());

        $crawler = $this->client->request('GET', '/settings');
        self::assertCount(1, $crawler->filter('a.list-card[href="/profile"]'));
    }

    public function testUpdateTheDisplayName(): void
    {
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Enregistrer', ['profile[displayName]' => 'Alicia']);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'profil a été mis à jour');
        self::assertSelectorTextContains('h1', 'Alicia');
        self::assertSelectorTextContains('.sidebar-user-name', 'Alicia');
        self::assertSame('Alicia', $this->reload()->getDisplayName());
    }

    public function testChooseAProfileIconThenGoBackToTheInitial(): void
    {
        $crawler = $this->client->request('GET', '/profile');
        // 10 pictogrammes + « Initiale » ; sans choix, la pastille affiche l'initiale.
        self::assertCount(11, $crawler->filter('.avatar-choice input[type="radio"]'));
        self::assertSelectorTextContains('.profile-head .avatar', 'A');

        $this->client->submitForm('Enregistrer', ['profile[displayName]' => 'Alice', 'profile[avatar]' => 'rocket']);
        self::assertResponseRedirects('/profile');
        $crawler = $this->client->followRedirect();
        self::assertSame(Avatar::Rocket, $this->reload()->getAvatar());
        self::assertCount(1, $crawler->filter('.profile-head .avatar svg'));
        self::assertCount(1, $crawler->filter('.sidebar-user .avatar svg'));
        self::assertSame('rocket', $crawler->filter('.avatar-choice input:checked')->attr('value'));

        $this->client->submitForm('Enregistrer', ['profile[displayName]' => 'Alice', 'profile[avatar]' => '']);
        $crawler = $this->client->followRedirect();
        self::assertNull($this->reload()->getAvatar());
        self::assertCount(0, $crawler->filter('.profile-head .avatar svg'));
        self::assertSelectorTextContains('.profile-head .avatar', 'A');
    }

    public function testAnUnknownProfileIconIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/profile');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['profile[avatar]']->disableValidation()->setValue('skull');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->reload()->getAvatar());
    }

    public function testAnEmptyDisplayNameIsRejectedAndTheMenuKeepsTheSavedName(): void
    {
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Enregistrer', ['profile[displayName]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sidebar-user-name', 'Alice');
        self::assertSame('Alice', $this->reload()->getDisplayName());
    }

    public function testChangeTheEmailAndStayLoggedIn(): void
    {
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Changer l\'adresse e-mail', [
            'change_email[email]' => 'Alice.Nouvelle@Example.TEST',
            'change_email[currentPassword]' => self::PASSWORD,
        ]);

        self::assertResponseRedirects('/profile');
        self::assertSame('alice.nouvelle@example.test', $this->reload()->getEmail(), 'L\'adresse est enregistrée en minuscules.');

        // La session reste ouverte avec la nouvelle adresse.
        $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-head', 'alice.nouvelle@example.test');

        // Connexion possible avec la nouvelle adresse, plus avec l'ancienne.
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'alice@example.test', '_password' => self::PASSWORD]);
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'alice.nouvelle@example.test', '_password' => self::PASSWORD]);
        self::assertResponseRedirects('/');
    }

    public function testChangingTheEmailNeedsTheCurrentPassword(): void
    {
        foreach (['mauvais-mot-de-passe', ''] as $password) {
            $this->client->request('GET', '/profile');
            $this->client->submitForm('Changer l\'adresse e-mail', [
                'change_email[email]' => 'pirate@example.test',
                'change_email[currentPassword]' => $password,
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('.sidebar-user', 'Alice');
            self::assertSame('alice@example.test', $this->reload()->getEmail());
        }
    }

    public function testTheEmailMustBeValidAndUnique(): void
    {
        $this->createUser('bob@example.test', 'Bob');

        foreach (['bob@example.test', 'pas-un-email'] as $email) {
            $this->client->request('GET', '/profile');
            $this->client->submitForm('Changer l\'adresse e-mail', [
                'change_email[email]' => $email,
                'change_email[currentPassword]' => self::PASSWORD,
            ]);
            self::assertResponseStatusCodeSame(422, $email);
            self::assertSame('alice@example.test', $this->reload()->getEmail());
        }
    }

    public function testChangeThePasswordAndStayLoggedIn(): void
    {
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Changer le mot de passe', [
            'update_password[currentPassword]' => self::PASSWORD,
            'update_password[plainPassword][first]' => self::NEW_PASSWORD,
            'update_password[plainPassword][second]' => self::NEW_PASSWORD,
        ]);

        self::assertResponseRedirects('/profile');
        $user = $this->reload();
        self::assertTrue($this->passwordIsValid($user, self::NEW_PASSWORD));
        self::assertFalse($this->passwordIsValid($user, self::PASSWORD));

        $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
    }

    public function testThePasswordChangeIsValidated(): void
    {
        $cases = [
            'mot de passe actuel incorrect' => [['update_password[currentPassword]' => 'incorrect']],
            'mot de passe actuel vide' => [['update_password[currentPassword]' => '']],
            'confirmation différente' => [['update_password[plainPassword][second]' => 'autre-chose-du-tout']],
            'trop court' => [['update_password[plainPassword][first]' => 'court', 'update_password[plainPassword][second]' => 'court']],
        ];

        foreach ($cases as $case => [$overrides]) {
            $this->client->request('GET', '/profile');
            $this->client->submitForm('Changer le mot de passe', $overrides + [
                'update_password[currentPassword]' => self::PASSWORD,
                'update_password[plainPassword][first]' => self::NEW_PASSWORD,
                'update_password[plainPassword][second]' => self::NEW_PASSWORD,
            ]);
            self::assertResponseStatusCodeSame(422, $case);
        }

        self::assertTrue($this->passwordIsValid($this->reload(), self::PASSWORD), 'Le mot de passe n\'a pas changé.');
    }

    public function testChangingThePasswordEndsTheOtherSessions(): void
    {
        // Session ouverte ailleurs (un autre navigateur).
        static::ensureKernelShutdown();
        $other = static::createClient();
        $other->loginUser($this->manager->getRepository(User::class)->findOneBy(['email' => 'alice@example.test']));
        $other->request('GET', '/profile');
        self::assertSame(200, $other->getResponse()->getStatusCode());

        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->manager = static::getContainer()->get('doctrine')->getManager();
        $this->client->loginUser($this->manager->getRepository(User::class)->findOneBy(['email' => 'alice@example.test']));
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Changer le mot de passe', [
            'update_password[currentPassword]' => self::PASSWORD,
            'update_password[plainPassword][first]' => self::NEW_PASSWORD,
            'update_password[plainPassword][second]' => self::NEW_PASSWORD,
        ]);
        self::assertResponseRedirects('/profile');

        $other->request('GET', '/profile');
        self::assertTrue($other->getResponse()->isRedirect(), 'L\'autre session doit être déconnectée.');
        self::assertSame('/login', parse_url((string) $other->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    public function testDeletingTheAccountNeedsTheCurrentPassword(): void
    {
        $this->fillWithData($this->user, 'Alice');

        foreach (['mauvais', ''] as $password) {
            $this->client->request('GET', '/profile');
            $this->client->submitForm('Supprimer définitivement mon compte', ['delete_account[currentPassword]' => $password]);
            self::assertResponseStatusCodeSame(422);
        }

        $this->manager->clear();
        self::assertNotNull($this->manager->getRepository(User::class)->findOneBy(['email' => 'alice@example.test']));
        self::assertSame(1, $this->manager->getRepository(Account::class)->count([]));
    }

    public function testDeletingTheAccountRemovesTheUserAndAllHisDataOnly(): void
    {
        $bob = $this->createUser('bob@example.test', 'Bob');
        $this->fillWithData($bob, 'Bob');
        $this->fillWithData($this->user, 'Alice');
        $this->manager->persist(new ResetPasswordRequest($this->user, new \DateTimeImmutable('+1 hour'), 'selector-alice-0001', 'hashed-token-alice'));
        $this->manager->flush();
        $this->manager->clear();

        $this->client->request('GET', '/profile');
        $this->client->submitForm('Supprimer définitivement mon compte', ['delete_account[currentPassword]' => self::PASSWORD]);

        self::assertResponseRedirects('/login');
        $this->manager->clear();
        self::assertNull($this->manager->getRepository(User::class)->findOneBy(['email' => 'alice@example.test']));
        self::assertSame(0, $this->manager->getRepository(ResetPasswordRequest::class)->count([]));

        // Seules les données de Bob subsistent.
        foreach ([Account::class, Category::class, Transaction::class, RecurringTransaction::class, Budget::class, Goal::class] as $class) {
            self::assertSame(1, $this->manager->getRepository($class)->count([]), $class);
        }
        $bobsAccount = $this->manager->getRepository(Account::class)->findOneBy([]);
        self::assertSame('bob@example.test', $bobsAccount->getOwner()->getEmail());

        // La session est fermée et un message le confirme.
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'ont été supprimés');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testADeletedAccountCanNoLongerLogIn(): void
    {
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Supprimer définitivement mon compte', ['delete_account[currentPassword]' => self::PASSWORD]);

        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'alice@example.test', '_password' => self::PASSWORD]);

        self::assertResponseRedirects('/login');
    }

    public function testTheProfileRequiresALogin(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->client->request('GET', '/profile');
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/profile/delete', ['delete_account' => ['currentPassword' => self::PASSWORD]]);
        self::assertResponseRedirects('/login');
    }
}
