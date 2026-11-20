<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\GoalContribution;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\Icon;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use App\Service\BackupExporter;

/** Sauvegarde et restauration des données d'un utilisateur : contenu, aller-retour, remplacement, atomicité, isolation. */
final class BackupTest extends AbstractCrudTestCase
{
    /** Jeu de données varié pour Alice : tous les types d'éléments, avec leurs liens. */
    private function seedRich(?User $owner = null): void
    {
        $owner ??= $this->user;
        $courant = (new Account())->setOwner($owner)->setName('Compte courant')->setType(AccountType::Checking)->setCurrency('EUR')->setInitialBalance('1500.00');
        $livret = (new Account())->setOwner($owner)->setName('Livret A')->setType(AccountType::Savings)->setCurrency('EUR')->setInitialBalance('-250.50');
        $usd = (new Account())->setOwner($owner)->setName('Voyage')->setType(AccountType::Travel)->setCurrency('USD')->setInitialBalance('800.00');
        $housing = (new Category())->setOwner($owner)->setName('Logement')->setIcon(Icon::Home)->setColor('#1d6cf0');
        $food = (new Category())->setOwner($owner)->setName('Alimentation « bio » & co')->setIcon(Icon::Basket);
        $none = (new Category())->setOwner($owner)->setName('Sans icône ni couleur');
        foreach ([$courant, $livret, $usd, $housing, $food, $none] as $entity) {
            $this->manager->persist($entity);
        }

        $rule = (new RecurringTransaction())->setLabel('Loyer')->setAmount('720.00')->setType(TransactionType::Expense)->setNote('Chaque mois')
            ->setAccount($courant)->setCategory($housing)->setFrequency(RecurrenceFrequency::Monthly)
            ->setStartDate(new \DateTimeImmutable('2026-09-05'))->setEndDate(new \DateTimeImmutable('2027-08-05'))
            ->setLastGeneratedDate(new \DateTimeImmutable('2026-11-05'))->setActive(true);
        $paused = (new RecurringTransaction())->setLabel('Salle de sport')->setAmount('29.90')->setType(TransactionType::Expense)
            ->setAccount($courant)->setCategory($none)->setFrequency(RecurrenceFrequency::Weekly)
            ->setStartDate(new \DateTimeImmutable('2026-10-01'))->setActive(false);
        $this->manager->persist($rule);
        $this->manager->persist($paused);

        foreach ([['2026-09-05', '720.00'], ['2026-10-05', '720.00'], ['2026-11-05', '720.00']] as [$date, $amount]) {
            $this->manager->persist((new Transaction())->setAccount($courant)->setCategory($housing)->setType(TransactionType::Expense)
                ->setDate(new \DateTimeImmutable($date))->setLabel('Loyer')->setAmount($amount)->setRecurringTransaction($rule));
        }
        $this->manager->persist((new Transaction())->setAccount($courant)->setCategory($food)->setType(TransactionType::Expense)
            ->setDate(new \DateTimeImmutable('2026-11-18'))->setLabel('Café <b>Léon</b> — "spécial" \'été\'')->setAmount('3.20')->setNote("Ligne 1\nLigne 2"));
        $this->manager->persist((new Transaction())->setAccount($courant)->setCategory($none)->setType(TransactionType::Income)
            ->setDate(new \DateTimeImmutable('2026-11-03'))->setLabel('Salaire')->setAmount('2500.00'));
        $this->manager->persist((new Transaction())->setAccount($usd)->setCategory($food)->setType(TransactionType::Expense)
            ->setDate(new \DateTimeImmutable('2026-11-12'))->setLabel('Hôtel')->setAmount('90.00'));

        $this->manager->persist((new Budget())->setCategory($food)->setMonth(new \DateTimeImmutable('2026-11-01'))->setAmount('300.00'));
        $this->manager->persist((new Budget())->setCategory($food)->setMonth(new \DateTimeImmutable('2026-12-01'))->setAmount('320.00')->setCurrency('EUR'));

        $goal = (new Goal())->setOwner($owner)->setName('Voiture')->setTargetAmount('5000.00')->setSavedAmount('3200.00')
            ->setTargetDate(new \DateTimeImmutable('2027-06-01'))->setIcon(Icon::Car);
        $noDate = (new Goal())->setOwner($owner)->setName('Nouveau PC')->setTargetAmount('1500.00')->setSavedAmount('0.00');
        $this->manager->persist($goal);
        $this->manager->persist($noDate);
        $this->manager->persist((new GoalContribution())->setGoal($goal)->setAmount('300.00')->setDate(new \DateTimeImmutable('2026-08-04'))->setNote('Prime'));
        $this->manager->persist((new GoalContribution())->setGoal($goal)->setAmount('-100.00')->setDate(new \DateTimeImmutable('2026-10-04')));

        $this->manager->persist((new Transfer())->setFromAccount($courant)->setToAccount($livret)->setAmount('150.00')->setDate(new \DateTimeImmutable('2026-11-10'))->setNote('Épargne'));
        $this->manager->flush();

        // Comme en production : chaque requête repart d'un gestionnaire d'entités vide (les collections inverses sont relues en base).
        $this->refresh();
    }

    /** Vide le gestionnaire d'entités et recharge l'utilisateur de test (les helpers s'en servent comme propriétaire). */
    private function refresh(): void
    {
        $id = $this->user->getId();
        $this->manager->clear();
        $this->user = $this->manager->getRepository(User::class)->find($id);
    }

    private function counts(User $user): array
    {
        $id = $user->getId();
        $this->refresh();
        $user = $this->manager->getRepository(User::class)->find($id);
        $em = $this->manager;

        return [
            'accounts' => $em->getRepository(Account::class)->count(['owner' => $user]),
            'categories' => $em->getRepository(Category::class)->count(['owner' => $user]),
            'transactions' => (int) $em->createQuery('SELECT COUNT(t) FROM '.Transaction::class.' t JOIN t.account a WHERE a.owner = :u')->setParameter('u', $user)->getSingleScalarResult(),
            'recurring' => (int) $em->createQuery('SELECT COUNT(r) FROM '.RecurringTransaction::class.' r JOIN r.account a WHERE a.owner = :u')->setParameter('u', $user)->getSingleScalarResult(),
            'budgets' => (int) $em->createQuery('SELECT COUNT(b) FROM '.Budget::class.' b JOIN b.category c WHERE c.owner = :u')->setParameter('u', $user)->getSingleScalarResult(),
            'goals' => $em->getRepository(Goal::class)->count(['owner' => $user]),
            'contributions' => (int) $em->createQuery('SELECT COUNT(g) FROM '.GoalContribution::class.' g JOIN g.goal o WHERE o.owner = :u')->setParameter('u', $user)->getSingleScalarResult(),
            'transfers' => (int) $em->createQuery('SELECT COUNT(t) FROM '.Transfer::class.' t JOIN t.fromAccount a WHERE a.owner = :u')->setParameter('u', $user)->getSingleScalarResult(),
        ];
    }

    /** Export comparable : les identifiants de la base sont remplacés par les noms, et la date d'export retirée. */
    private function normalized(User $user): array
    {
        $this->manager->clear();
        $data = static::getContainer()->get(BackupExporter::class)->export($this->manager->getRepository(User::class)->find($user->getId()));
        unset($data['exportedAt']);
        $accounts = array_column($data['accounts'], 'name', 'id');
        $categories = array_column($data['categories'], 'name', 'id');
        $rules = array_column($data['recurring'], 'label', 'id');
        $data['accounts'] = array_map(static fn (array $a): array => array_diff_key($a, ['id' => 1]), $data['accounts']);
        $data['categories'] = array_map(static fn (array $c): array => array_diff_key($c, ['id' => 1]), $data['categories']);
        $data['recurring'] = array_map(static fn (array $r): array => array_diff_key(['account' => $accounts[$r['account']], 'category' => $categories[$r['category']]] + $r, ['id' => 1]), $data['recurring']);
        $data['transactions'] = array_map(static fn (array $t): array => ['account' => $accounts[$t['account']], 'category' => $categories[$t['category']], 'recurring' => null === $t['recurring'] ? null : $rules[$t['recurring']]] + $t, $data['transactions']);
        $data['budgets'] = array_map(static fn (array $b): array => ['category' => $categories[$b['category']]] + $b, $data['budgets']);
        $data['transfers'] = array_map(static fn (array $t): array => ['from' => $accounts[$t['from']], 'to' => $accounts[$t['to']]] + $t, $data['transfers']);

        return $data;
    }

    private function json(?User $user = null): string
    {
        $id = ($user ?? $this->user)->getId();
        $this->refresh();

        return static::getContainer()->get(BackupExporter::class)->toJson($this->manager->getRepository(User::class)->find($id));
    }

    /** Envoie la page de restauration avec un fichier. */
    private function restore(string $json, array $values = []): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('sauvegarde-', true).'.json';
        file_put_contents($path, $json);
        $crawler = $this->client->request('GET', '/profile/backup/restore');
        $form = $crawler->selectButton('Remplacer mes données par la sauvegarde')->form($values + [
            'backup_restore[currentPassword]' => self::PASSWORD,
            'backup_restore[confirm]' => true,
        ]);
        $form['backup_restore[file]']->upload($path);
        $this->client->submit($form);
        @unlink($path);
    }

    private function clearAll(User $user): void
    {
        static::getContainer()->get(\App\Service\UserDataPurger::class)->purgeData($this->manager->getRepository(User::class)->find($user->getId()));
        $this->manager->clear();
    }

    // ---------------------------------------------------------------------------------------------- téléchargement

    public function testTheBackupIsADownloadableJsonDocumentWithEveryKindOfData(): void
    {
        $this->seedRich();

        $this->client->request('GET', '/profile/backup/download');

        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        self::assertStringContainsString('application/json', (string) $headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/attachment; filename=finora-sauvegarde-\d{4}-\d{2}-\d{2}\.json/', (string) $headers->get('Content-Disposition'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('finora-backup', $data['format']);
        self::assertSame(1, $data['version']);
        self::assertCount(3, $data['accounts']);
        self::assertCount(3, $data['categories']);
        self::assertCount(6, $data['transactions']);
        self::assertCount(2, $data['recurring']);
        self::assertCount(2, $data['budgets']);
        self::assertCount(2, $data['goals']);
        self::assertCount(2, $data['goals'][0]['contributions'] ?? $data['goals'][1]['contributions']);
        self::assertCount(1, $data['transfers']);
    }

    public function testTheBackupHoldsNeitherTheEmailNorThePassword(): void
    {
        $this->seedRich();

        $this->client->request('GET', '/profile/backup/download');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('alice@example.test', $content);
        self::assertStringNotContainsString($this->user->getPassword(), $content);
        self::assertStringNotContainsString('password', strtolower($content));
        self::assertStringContainsString('Café <b>Léon</b> — \"spécial\"', $content, 'Accents et caractères spéciaux conservés tels quels');
    }

    public function testTheBackupContainsOnlyTheUsersOwnData(): void
    {
        $this->seedRich();
        $bob = $this->createUser('bob@example.test', 'Bob');
        $this->createAccount('Compte secret de Bob', 'EUR', $bob);

        $this->client->request('GET', '/profile/backup/download');

        self::assertStringNotContainsString('Compte secret de Bob', (string) $this->client->getResponse()->getContent());
    }

    public function testTheProfileOffersTheBackup(): void
    {
        $this->client->request('GET', '/profile');

        self::assertSelectorExists('#sauvegarde a[href="/profile/backup/download"]');
        self::assertSelectorExists('#sauvegarde a[href="/profile/backup/restore"]');
    }

    // ---------------------------------------------------------------------------------------------- restauration

    public function testRestoringABackupRebuildsEverythingIdentically(): void
    {
        $this->seedRich();
        $before = $this->normalized($this->user);
        $counts = $this->counts($this->user);
        $json = $this->json();

        $this->clearAll($this->user);
        self::assertSame(0, $this->counts($this->user)['transactions']);

        $this->restore($json);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Sauvegarde restaurée');
        self::assertSelectorTextContains('.flash-success', '6 transactions');
        self::assertSelectorTextContains('.flash-success', '3 comptes');
        self::assertSame($counts, $this->counts($this->user));
        self::assertEquals($before, $this->normalized($this->user), 'Même contenu, champ par champ');
    }

    public function testRecurringLinksContributionsAndTransfersAreRestored(): void
    {
        $this->seedRich();
        $json = $this->json();
        $this->clearAll($this->user);

        $this->restore($json);

        $this->manager->clear();
        $loyers = $this->manager->getRepository(Transaction::class)->findBy(['label' => 'Loyer']);
        self::assertCount(3, $loyers);
        foreach ($loyers as $loyer) {
            self::assertSame('Loyer', $loyer->getRecurringTransaction()?->getLabel());
        }
        $voiture = $this->manager->getRepository(Goal::class)->findOneBy(['name' => 'Voiture']);
        self::assertSame('3200.00', $voiture->getSavedAmount());
        self::assertCount(2, $voiture->getContributions());
        $transfer = $this->manager->getRepository(Transfer::class)->findOneBy([]);
        self::assertSame('Livret A', $transfer->getToAccount()->getName());
        $rule = $this->manager->getRepository(RecurringTransaction::class)->findOneBy(['label' => 'Loyer']);
        self::assertSame('2026-11-05', $rule->getLastGeneratedDate()->format('Y-m-d'));
        self::assertFalse($this->manager->getRepository(RecurringTransaction::class)->findOneBy(['label' => 'Salle de sport'])->isActive());
    }

    public function testRestoringReplacesTheCurrentData(): void
    {
        $this->seedRich();
        $json = $this->json();
        // Données ajoutées depuis la sauvegarde : elles doivent disparaître.
        $this->createDatedTransaction($this->manager->getRepository(Account::class)->findOneBy(['name' => 'Compte courant']), $this->createCategory('Nouvelle'), TransactionType::Expense, '9.99', '2026-11-19', 'Ajouté après');

        $this->restore($json);

        $this->manager->clear();
        self::assertNull($this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Ajouté après']));
        self::assertNull($this->manager->getRepository(Category::class)->findOneBy(['name' => 'Nouvelle']));
        self::assertSame(6, $this->counts($this->user)['transactions']);
    }

    public function testTheAccountItselfIsNotTouched(): void
    {
        $this->seedRich();
        $json = $this->json();
        $hash = $this->user->getPassword();

        $this->restore($json);

        $this->manager->clear();
        $user = $this->manager->getRepository(User::class)->find($this->user->getId());
        self::assertSame('alice@example.test', $user->getEmail());
        self::assertSame('Alice', $user->getDisplayName());
        self::assertSame($hash, $user->getPassword());
    }

    public function testABackupCanBeRestoredInAnotherAccountWithoutTouchingTheFirst(): void
    {
        $this->seedRich();
        $json = $this->json();
        $aliceCounts = $this->counts($this->user);

        $bob = $this->createUser('bob@example.test', 'Bob');
        $this->client->loginUser($bob);
        $this->restore($json);

        self::assertResponseRedirects('/profile');
        self::assertSame($aliceCounts, $this->counts($bob));
        self::assertSame($aliceCounts, $this->counts($this->user), 'Les données d\'Alice sont intactes');
        // Les éléments de Bob sont bien à lui.
        foreach ($this->manager->getRepository(Account::class)->findBy(['owner' => $bob]) as $account) {
            self::assertSame($bob->getId(), $account->getOwner()->getId());
        }
    }

    public function testAnEmptyBackupEmptiesTheAccount(): void
    {
        $this->seedRich();
        $empty = json_encode(['format' => 'finora-backup', 'version' => 1], \JSON_THROW_ON_ERROR);

        $this->restore($empty);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'aucune donnée');
        self::assertSame(array_fill_keys(['accounts', 'categories', 'transactions', 'recurring', 'budgets', 'goals', 'contributions', 'transfers'], 0), $this->counts($this->user));
    }

    // ---------------------------------------------------------------------------------------------- confirmations

    public function testTheCurrentPasswordIsRequired(): void
    {
        $this->seedRich();
        $before = $this->counts($this->user);

        $this->restore($this->json(), ['backup_restore[currentPassword]' => 'mauvais']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Mot de passe actuel incorrect');
        self::assertSame($before, $this->counts($this->user));
    }

    public function testTheReplacementMustBeConfirmed(): void
    {
        $this->seedRich();
        $before = $this->counts($this->user);

        $this->restore($this->json(), ['backup_restore[confirm]' => false]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cochez la case');
        self::assertSame($before, $this->counts($this->user));
    }

    public function testAFileIsRequired(): void
    {
        $crawler = $this->client->request('GET', '/profile/backup/restore');
        $this->client->submit($crawler->selectButton('Remplacer mes données par la sauvegarde')->form([
            'backup_restore[currentPassword]' => self::PASSWORD,
            'backup_restore[confirm]' => true,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choisissez un fichier');
    }

    // ---------------------------------------------------------------------------------------------- fichiers invalides : rien ne change

    /** @return iterable<string, array{callable(array): array, string}> */
    public static function badBackups(): iterable
    {
        yield 'pas du JSON' => [static fn (array $d): array => ['__raw' => 'ceci n\'est pas du json'], 'JSON valide'];
        yield 'mauvais format' => [static fn (array $d): array => ['format' => 'autre'] + $d, 'pas une sauvegarde FINORA'];
        yield 'version inconnue' => [static fn (array $d): array => ['version' => 99] + $d, 'Version de sauvegarde'];
        yield 'section mal formée' => [static fn (array $d): array => ['accounts' => 'oups'] + $d, 'mal formée'];
        yield 'transaction sans compte connu' => [static function (array $d): array { $d['transactions'][1]['account'] = 9999; return $d; }, 'Transaction n°2'];
        yield 'montant négatif' => [static function (array $d): array { $d['transactions'][0]['amount'] = '-5.00'; return $d; }, 'Transaction n°1'];
        yield 'type inconnu' => [static function (array $d): array { $d['transactions'][2]['type'] = 'virement'; return $d; }, 'Transaction n°3'];
        yield 'date impossible' => [static function (array $d): array { $d['transactions'][0]['date'] = '2026-02-30'; return $d; }, 'AAAA-MM-JJ'];
        yield 'devise inconnue' => [static function (array $d): array { $d['accounts'][0]['currency'] = 'ZZZ'; return $d; }, 'Compte n°1'];
        yield 'libellé trop long' => [static function (array $d): array { $d['transactions'][0]['label'] = str_repeat('x', 300); return $d; }, 'trop long'];
        yield 'catégories en double' => [static function (array $d): array { $d['categories'][1]['name'] = $d['categories'][0]['name']; return $d; }, 'existe déjà dans la sauvegarde'];
        yield 'identifiants en double' => [static function (array $d): array { $d['accounts'][1]['id'] = $d['accounts'][0]['id']; return $d; }, 'identifiant en double'];
        yield 'couleur invalide' => [static function (array $d): array { $d['categories'][0]['color'] = 'bleu'; return $d; }, 'Catégorie n°1'];
        yield 'budgets en double' => [static function (array $d): array { $d['budgets'][1]['month'] = $d['budgets'][0]['month']; return $d; }, 'existe déjà pour cette catégorie'];
        yield 'échéance de récurrence en double' => [static function (array $d): array { $d['transactions'][1]['date'] = $d['transactions'][0]['date']; return $d; }, 'deux fois'];
        yield 'virement vers un compte inconnu' => [static function (array $d): array { $d['transfers'][0]['to'] = 424242; return $d; }, 'Virement n°1'];
        yield 'virement entre devises' => [static function (array $d): array { $d['transfers'][0]['to'] = $d['accounts'][2]['id']; return $d; }, 'Virement n°1'];
        yield 'booléen invalide' => [static function (array $d): array { $d['recurring'][0]['active'] = 'oui'; return $d; }, 'Récurrence n°1'];
        yield 'icône inconnue' => [static function (array $d): array { $d['categories'][0]['icon'] = 'fusée'; return $d; }, 'Catégorie n°1'];
    }

    /**
     * @dataProvider badBackups
     *
     * @param callable(array): array $mutate
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badBackups')]
    public function testAnInvalidBackupIsRefusedAndChangesNothing(callable $mutate, string $message): void
    {
        $this->seedRich();
        $data = json_decode($this->json(), true, flags: \JSON_THROW_ON_ERROR);
        $broken = $mutate($data);
        $json = isset($broken['__raw']) ? $broken['__raw'] : json_encode($broken, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $before = $this->normalized($this->user);

        $this->restore($json);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->client->getResponse()->getContent() ? html_entity_decode((string) $this->client->getResponse()->getContent()) : '');
        self::assertStringContainsString('n\'ont pas été modifiées', html_entity_decode((string) $this->client->getResponse()->getContent()));
        self::assertEquals($before, $this->normalized($this->user), 'Les données actuelles sont intactes (tout ou rien)');
    }

    public function testAfterAFailedRestoreTheApplicationStillWorks(): void
    {
        $this->seedRich();
        $this->restore('pas du json');
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/transaction');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tx-list', 'Salaire');
    }

    // ---------------------------------------------------------------------------------------------- accès

    public function testTheBackupRoutesRequireALogin(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();

        foreach (['/profile/backup/download', '/profile/backup/restore'] as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('http://localhost/login', null, $url);
        }
    }
}
