<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\AccountShare;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\ShareRole;
use App\Enum\ShareStatus;
use App\Enum\TransactionType;

/**
 * Partage d'un compte : invitation par le propriétaire, acceptation, deux niveaux de droits, saisie par l'invité,
 * isolation (les comptes partagés n'entrent jamais dans les totaux de l'invité), et suppression du partage.
 *
 * Alice est la propriétaire (utilisatrice connectée par défaut), Bob la personne invitée.
 */
final class AccountSharingTest extends AbstractCrudTestCase
{
    private User $bob;
    private Account $joint;
    private Category $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bob = $this->createUser('bob@example.test', 'Bob');
        $this->joint = $this->createAccount('Compte commun');
        $this->joint->setInitialBalance('1000.00');
        $this->food = $this->createCategory('Alimentation');
        $this->createDatedTransaction($this->joint, $this->food, TransactionType::Expense, '85.40', '2026-11-18', 'Courses');
        $this->createDatedTransaction($this->joint, $this->food, TransactionType::Income, '500.00', '2026-11-03', 'Remboursement');
    }

    private function share(ShareRole $role = ShareRole::Editor, ShareStatus $status = ShareStatus::Accepted, ?User $invitee = null, ?Account $account = null): AccountShare
    {
        // Après une requête du client, le gestionnaire est neuf : on repasse par des références.
        $account = $this->manager->getReference(Account::class, ($account ?? $this->joint)->getId());
        $invitee = $this->manager->getReference(User::class, ($invitee ?? $this->bob)->getId());
        $share = (new AccountShare())->setAccount($account)->setInvitee($invitee)->setRole($role)->setStatus($status);
        $this->manager->persist($share);
        $this->manager->flush();

        return $share;
    }

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user);
    }

    private function sharesCount(): int
    {
        return $this->manager->getRepository(AccountShare::class)->count([]);
    }

    // ---------------------------------------------------------------------------------------------- invitation (propriétaire)

    public function testTheOwnerSeesTheSharePageAndTheButtonOnTheAccount(): void
    {
        $this->client->request('GET', '/account/'.$this->joint->getId());
        self::assertSelectorExists('a[href="/account/'.$this->joint->getId().'/share"]');

        $this->client->request('GET', '/account/'.$this->joint->getId().'/share');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.share-card', 'Ce compte n\'est partagé avec personne');
        self::assertSelectorTextContains('.share-card', 'sans qu\'il compte dans ses propres totaux');
        self::assertCount(2, $this->client->getCrawler()->filter('input[name="share_invite[role]"]'));
    }

    public function testInvitingAnExistingUserCreatesAPendingInvitation(): void
    {
        $crawler = $this->client->request('GET', '/account/'.$this->joint->getId().'/share');
        $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form([
            'share_invite[email]' => ' Bob@Example.test ',
            'share_invite[role]' => 'viewer',
        ]));

        self::assertResponseRedirects('/account/'.$this->joint->getId().'/share');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Invitation envoyée à Bob');
        self::assertSelectorTextContains('.share-list', 'Bob');
        self::assertSelectorTextContains('.share-list', 'En attente');

        $share = $this->manager->getRepository(AccountShare::class)->findOneBy([]);
        self::assertSame(ShareStatus::Pending, $share->getStatus());
        self::assertSame(ShareRole::Viewer, $share->getRole());
        self::assertSame($this->bob->getId(), $share->getInvitee()->getId());
    }

    public function testAnUnknownAddressSelfAndDuplicatesAreRefused(): void
    {
        $url = '/account/'.$this->joint->getId().'/share';

        foreach ([
            ['inconnu@example.test', 'Aucun compte FINORA'],
            ['alice@example.test', 'vous appartient déjà'],
        ] as [$email, $message]) {
            $crawler = $this->client->request('GET', $url);
            $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form(['share_invite[email]' => $email, 'share_invite[role]' => 'viewer']));
            self::assertResponseStatusCodeSame(422, $email);
            self::assertSelectorTextContains('body', $message);
        }

        $this->share(ShareRole::Viewer, ShareStatus::Pending);
        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form(['share_invite[email]' => 'bob@example.test', 'share_invite[role]' => 'editor']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'déjà été invitée');
        self::assertSame(1, $this->sharesCount());
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/account/'.$this->joint->getId().'/share');
        $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form(['share_invite[email]' => 'pas-une-adresse', 'share_invite[role]' => 'viewer']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->sharesCount());
    }

    public function testInvitationsAreRateLimited(): void
    {
        $url = '/account/'.$this->joint->getId().'/share';
        for ($i = 0; $i < 10; ++$i) {
            $crawler = $this->client->request('GET', $url);
            $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form(['share_invite[email]' => "inconnu{$i}@example.test", 'share_invite[role]' => 'viewer']));
        }

        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form(['share_invite[email]' => 'bob@example.test', 'share_invite[role]' => 'viewer']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Trop d\'invitations');
        self::assertSame(0, $this->sharesCount(), 'La onzième tentative n\'a rien créé');
    }

    public function testOnlyTheOwnerCanManageTheShares(): void
    {
        $share = $this->share(ShareRole::Editor);
        $this->loginAs($this->bob);

        $this->client->request('GET', '/account/'.$this->joint->getId().'/share');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/account/share/'.$share->getId().'/role', ['role' => 'viewer']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/account/share/'.$share->getId().'/remove');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/account/'.$this->joint->getId());
        self::assertResponseStatusCodeSame(403, 'Bob ne gère pas le compte lui-même');
        $this->client->request('GET', '/account/'.$this->joint->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheOwnerCanChangeTheRoleAndRemoveTheAccess(): void
    {
        $share = $this->share(ShareRole::Viewer);
        $crawler = $this->client->request('GET', '/account/'.$this->joint->getId().'/share');

        $form = $crawler->filter('form.share-role')->form(['role' => 'editor']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Droits de Bob : lecture et saisie');
        $this->manager->clear();
        self::assertSame(ShareRole::Editor, $this->manager->getRepository(AccountShare::class)->find($share->getId())->getRole());

        $crawler = $this->client->request('GET', '/account/'.$this->joint->getId().'/share');
        $this->client->submit($crawler->filter('form[action$="/remove"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'n\'a plus accès');
        self::assertSame(0, $this->sharesCount());
    }

    public function testAnotherUsersAccountCannotBeShared(): void
    {
        $theirs = $this->createAccount('Compte de Bob', 'EUR', $this->bob);

        $this->client->request('GET', '/account/'.$theirs->getId().'/share');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheShareRoutesRequireALogin(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        foreach (['/shared', '/shared/account/1', '/account/1/share'] as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('http://localhost/login', null, $url);
        }
    }

    // ---------------------------------------------------------------------------------------------- invitation (invité)

    public function testThePendingInvitationIsVisibleAndCountedForTheInvitee(): void
    {
        $this->share(ShareRole::Viewer, ShareStatus::Pending);
        $this->loginAs($this->bob);

        $crawler = $this->client->request('GET', '/account');
        self::assertSelectorTextContains('.invitation-banner', 'une invitation à partager un compte');
        self::assertSame('1', trim($crawler->filter('.sidebar nav a[href="/account"] .alert-badge')->text()));
        self::assertSelectorExists('.tabbar a[href="/account"] .alert-badge');

        $crawler = $this->client->request('GET', '/shared');
        self::assertSelectorTextContains('.invitations', 'Compte commun');
        self::assertSelectorTextContains('.invitations', 'Alice');
        self::assertSelectorTextContains('.invitations', 'Lecture seule');
    }

    public function testThePendingInvitationIsInvisibleToEveryoneElse(): void
    {
        $this->share(ShareRole::Viewer, ShareStatus::Pending);
        $carol = $this->createUser('carol@example.test', 'Carol');
        $this->loginAs($carol);

        $this->client->request('GET', '/shared');
        self::assertSelectorTextNotContains('body', 'Compte commun');
        self::assertSelectorNotExists('.invitation-banner');
    }

    public function testAcceptingGivesAccessAndDecliningRemovesTheInvitation(): void
    {
        $accepted = $this->share(ShareRole::Viewer, ShareStatus::Pending);
        $other = $this->createAccount('Compte voyage');
        $declined = $this->share(ShareRole::Editor, ShareStatus::Pending, $this->bob, $other);
        $this->loginAs($this->bob);

        $crawler = $this->client->request('GET', '/shared');
        $this->client->submit($crawler->filter('form[action$="/invitation/'.$accepted->getId().'/accept"]')->form());
        self::assertResponseRedirects('/shared');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Vous avez maintenant accès au compte « Compte commun » de Alice');

        $this->client->submit($crawler->filter('form[action$="/invitation/'.$declined->getId().'/decline"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Invitation refusée');

        $this->manager->clear();
        self::assertSame(ShareStatus::Accepted, $this->manager->getRepository(AccountShare::class)->find($accepted->getId())->getStatus());
        self::assertNull($this->manager->getRepository(AccountShare::class)->find($declined->getId()));
    }

    public function testNobodyElseCanAnswerAnInvitation(): void
    {
        $share = $this->share(ShareRole::Editor, ShareStatus::Pending);

        // Ni la propriétaire, ni un tiers.
        foreach ([$this->user, $this->createUser('carol@example.test', 'Carol')] as $user) {
            $this->loginAs($user);
            foreach (['accept', 'decline'] as $action) {
                $this->client->request('POST', '/shared/invitation/'.$share->getId().'/'.$action);
                self::assertResponseStatusCodeSame(403, $user->getEmail().' '.$action);
            }
        }
        $this->manager->clear();
        self::assertSame(ShareStatus::Pending, $this->manager->getRepository(AccountShare::class)->find($share->getId())->getStatus());
    }

    public function testAWrongSecurityTokenDoesNotAnswerTheInvitation(): void
    {
        $share = $this->share(ShareRole::Viewer, ShareStatus::Pending);
        $this->loginAs($this->bob);

        $this->client->request('POST', '/shared/invitation/'.$share->getId().'/accept', ['_token' => 'faux']);

        $this->manager->clear();
        self::assertSame(ShareStatus::Pending, $this->manager->getRepository(AccountShare::class)->find($share->getId())->getStatus());
    }

    // ---------------------------------------------------------------------------------------------- consultation et saisie

    public function testAViewerSeesTheAccountBalanceAndTransactionsButCannotEdit(): void
    {
        $this->share(ShareRole::Viewer);
        $this->loginAs($this->bob);

        $this->client->request('GET', '/shared');
        self::assertSelectorTextContains('.list-card', 'Compte commun');
        self::assertSelectorTextContains('.list-card', 'de Alice');
        self::assertSelectorTextContains('.list-card', "1\u{202F}414,60", 'Solde : 1 000 + 500 − 85,40');

        $crawler = $this->client->request('GET', '/shared/account/'.$this->joint->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.shared-balance', "1\u{202F}414,60");
        self::assertSame(['Courses', 'Remboursement'], $crawler->filter('.tx-list .name')->each(static fn ($n): string => $n->text()));
        self::assertSelectorTextContains('body', 'lecture seule');
        self::assertSelectorNotExists('a[href*="/shared/"][href*="/transaction/new"]');
        self::assertSelectorNotExists('a[href*="/shared/transaction/"]');

        $this->client->request('GET', '/shared/account/'.$this->joint->getId().'/transaction/new');
        self::assertResponseStatusCodeSame(403);
        $transaction = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Courses']);
        $this->client->request('GET', '/shared/transaction/'.$transaction->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/shared/transaction/'.$transaction->getId().'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(2, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testAnEditorCanAddAnEditAndDeleteTransactionsWithTheOwnersCategories(): void
    {
        $this->share(ShareRole::Editor);
        $this->createCategory('Catégorie de Bob', null, $this->bob);
        $this->loginAs($this->bob);

        $crawler = $this->client->request('GET', '/shared/account/'.$this->joint->getId().'/transaction/new');
        self::assertResponseIsSuccessful();
        $options = $crawler->filter('#shared_transaction_category option')->each(static fn ($o): string => $o->text());
        self::assertContains('Alimentation', $options, 'Les catégories du propriétaire');
        self::assertNotContains('Catégorie de Bob', $options, 'Pas celles de l\'invité');

        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'shared_transaction[label]' => 'Pharmacie',
            'shared_transaction[amount]' => '24.90',
            'shared_transaction[type]' => 'expense',
            'shared_transaction[date]' => '2026-11-19',
            'shared_transaction[category]' => (string) $this->food->getId(),
        ]));
        self::assertResponseRedirects('/shared/account/'.$this->joint->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'enregistrée');
        self::assertSelectorTextContains('.tx-list', 'Pharmacie');
        self::assertSelectorTextContains('.tx-list', 'par Bob');

        $this->manager->clear();
        $created = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Pharmacie']);
        self::assertSame($this->joint->getId(), $created->getAccount()->getId(), 'Sur le compte partagé');
        self::assertSame($this->bob->getId(), $created->getCreatedBy()->getId());
        self::assertSame($this->user->getId(), $created->getOwner()->getId(), 'Elle appartient à la propriétaire');

        $crawler = $this->client->request('GET', '/shared/transaction/'.$created->getId().'/edit');
        $this->client->submit($crawler->selectButton('Mettre à jour')->form(['shared_transaction[amount]' => '30.00']));
        self::assertResponseRedirects();
        $this->manager->clear();
        self::assertSame('30.00', $this->manager->getRepository(Transaction::class)->find($created->getId())->getAmount());

        $crawler = $this->client->request('GET', '/shared/transaction/'.$created->getId().'/edit');
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/shared/account/'.$this->joint->getId());
        $this->manager->clear();
        self::assertNull($this->manager->getRepository(Transaction::class)->find($created->getId()));
    }

    public function testTheOwnerSeesWhatTheInviteeAddedAndWhoAddedIt(): void
    {
        $this->share(ShareRole::Editor);
        $this->loginAs($this->bob);
        $crawler = $this->client->request('GET', '/shared/account/'.$this->joint->getId().'/transaction/new');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'shared_transaction[label]' => 'Courses de Bob',
            'shared_transaction[amount]' => '12.00',
            'shared_transaction[type]' => 'expense',
            'shared_transaction[date]' => '2026-11-19',
            'shared_transaction[category]' => (string) $this->food->getId(),
        ]));

        $this->loginAs($this->user);
        $this->client->request('GET', '/transaction');

        self::assertSelectorTextContains('.tx-list', 'Courses de Bob');
        self::assertSelectorTextContains('.tx-list', 'par Bob');
        // Le solde du compte, côté propriétaire, tient compte de la saisie.
        $this->client->request('GET', '/account');
        self::assertSelectorTextContains('.list', "1\u{202F}402,60");
    }

    public function testTheOwnersOwnTransactionsCarryNoAuthorTag(): void
    {
        $this->client->request('GET', '/transaction');

        self::assertSelectorTextNotContains('.tx-list', 'par Alice');
    }

    public function testASharedAccountNeverCountsInTheInviteesTotals(): void
    {
        $this->share(ShareRole::Editor);
        $bobAccount = $this->createAccount('Compte de Bob', 'EUR', $this->bob);
        $bobAccount->setInitialBalance('200.00');
        $this->manager->flush();
        $this->loginAs($this->bob);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kpi', '200,00', 'Solde total de Bob : son compte seulement');
        self::assertSelectorTextNotContains('.kpis', "1\u{202F}414,60");

        $this->client->request('GET', '/account');
        self::assertSelectorTextContains('.list', 'Compte de Bob');
        self::assertSelectorTextNotContains('.list', 'Compte commun');

        $this->client->request('GET', '/transaction');
        self::assertSelectorTextNotContains('body', 'Courses');
        $this->client->request('GET', '/export/transactions');
        self::assertStringNotContainsString('Courses', (string) $this->client->getInternalResponse()->getContent());
        $this->client->request('GET', '/profile/backup/download');
        self::assertStringNotContainsString('Compte commun', (string) $this->client->getResponse()->getContent());
    }

    public function testAccessIsLimitedToTheSharedAccount(): void
    {
        $this->share(ShareRole::Editor);
        $private = $this->createAccount('Compte privé d\'Alice');
        $secret = $this->createDatedTransaction($private, $this->food, TransactionType::Expense, '99.00', '2026-11-10', 'Dépense secrète');
        $this->loginAs($this->bob);

        $this->client->request('GET', '/shared/account/'.$private->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/shared/account/'.$private->getId().'/transaction/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/shared/transaction/'.$secret->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/shared/transaction/'.$secret->getId().'/delete');
        self::assertResponseStatusCodeSame(403);
        // Les routes du propriétaire restent fermées à l'invité, même sur le compte partagé.
        $joint = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Courses']);
        foreach (['/transaction/'.$joint->getId(), '/transaction/'.$joint->getId().'/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testAPendingInviteeHasNoAccessYet(): void
    {
        $this->share(ShareRole::Editor, ShareStatus::Pending);
        $this->loginAs($this->bob);

        $this->client->request('GET', '/shared/account/'.$this->joint->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/shared/account/'.$this->joint->getId().'/transaction/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLosingTheAccessClosesTheAccount(): void
    {
        $share = $this->share(ShareRole::Editor);
        $this->loginAs($this->user);
        $crawler = $this->client->request('GET', '/account/'.$this->joint->getId().'/share');
        $this->client->submit($crawler->filter('form[action$="/remove"]')->form());

        $this->loginAs($this->bob);
        $this->client->request('GET', '/shared/account/'.$this->joint->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->manager->getRepository(AccountShare::class)->find($share->getId()));
    }

    public function testTheInviteeCanLeaveASharedAccount(): void
    {
        $this->share(ShareRole::Viewer);
        $this->loginAs($this->bob);

        $crawler = $this->client->request('GET', '/shared/account/'.$this->joint->getId());
        $this->client->submit($crawler->filter('.leave-form')->selectButton('Quitter ce compte partagé')->form());

        self::assertResponseRedirects('/shared');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Vous n\'avez plus accès au compte « Compte commun »');
        self::assertSame(0, $this->sharesCount());
        $this->client->request('GET', '/shared/account/'.$this->joint->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheOwnerSeesWhichAccountsAreShared(): void
    {
        $this->share(ShareRole::Viewer);
        $this->createAccount('Compte non partagé');

        $crawler = $this->client->request('GET', '/account');

        self::assertCount(1, $crawler->filter('.share-tag'));
        self::assertSelectorTextContains('.share-tag', '1');
    }

    // ---------------------------------------------------------------------------------------------- suppression

    public function testDeletingTheOwnerDeletesTheSharesAndKeepsTheInviteesData(): void
    {
        $share = $this->share(ShareRole::Editor);
        $bobAccount = $this->createAccount('Compte de Bob', 'EUR', $this->bob);
        $this->manager->flush();

        static::getContainer()->get(\App\Service\UserDataPurger::class)->purge($this->manager->getRepository(User::class)->find($this->user->getId()));
        $this->manager->clear();

        self::assertNull($this->manager->getRepository(AccountShare::class)->find($share->getId()));
        self::assertNotNull($this->manager->getRepository(Account::class)->find($bobAccount->getId()));
        self::assertNotNull($this->manager->getRepository(User::class)->find($this->bob->getId()));
    }

    public function testDeletingTheInviteeKeepsTheTransactionsHeEntered(): void
    {
        $this->share(ShareRole::Editor);
        $created = $this->createDatedTransaction($this->joint, $this->food, TransactionType::Expense, '10.00', '2026-11-19', 'Saisie de Bob');
        $created->setCreatedBy($this->bob);
        $this->manager->flush();
        $id = $created->getId();

        static::getContainer()->get(\App\Service\UserDataPurger::class)->purge($this->manager->getRepository(User::class)->find($this->bob->getId()));
        $this->manager->clear();

        $transaction = $this->manager->getRepository(Transaction::class)->find($id);
        self::assertNotNull($transaction, 'La transaction reste sur le compte de la propriétaire');
        self::assertNull($transaction->getCreatedBy());
        self::assertSame(0, $this->sharesCount());
    }

    public function testRoleLabelsAreInFrench(): void
    {
        self::assertSame('Lecture seule', ShareRole::Viewer->label());
        self::assertSame('Lecture et saisie', ShareRole::Editor->label());
        self::assertSame('En attente', ShareStatus::Pending->label());
    }
}
