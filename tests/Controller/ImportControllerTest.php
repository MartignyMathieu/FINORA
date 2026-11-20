<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\TransactionType;

/** Import CSV de transactions : envoi, correspondance des colonnes, aperçu, import, doublons, isolation. */
final class ImportControllerTest extends AbstractCrudTestCase
{
    private const SAMPLE = <<<'CSV'
        Date;Libellé;Montant;Catégorie
        2026-11-01;Salaire novembre;2 500,00;Revenus
        01/11/2026;Loyer;-720,00;Logement
        02/11/2026;Courses Leclerc;-64,30;Alimentation
        bientôt;Ligne cassée;-5,00;Autre
        2026-11-05;Montant illisible;douze;Autre
        CSV;

    private \App\Entity\Account $account;
    private Category $fallback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = $this->createAccount('Compte courant');
        $this->fallback = $this->createCategory('Divers');
    }

    private function upload(string $content, string $name = 'releve.csv'): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('import-', true).'-'.$name;
        file_put_contents($path, $content);
        $crawler = $this->client->request('GET', '/import/transactions');
        $form = $crawler->selectButton('Envoyer le fichier')->form();
        $form['import_upload[file]']->upload($path);
        $this->client->submit($form);
        @unlink($path);
    }

    /** Envoie le fichier puis soumet la correspondance ; renvoie la page obtenue. */
    private function map(string $content, array $values = [], string $button = 'Voir l\'aperçu'): \Symfony\Component\DomCrawler\Crawler
    {
        $this->upload($content);
        self::assertResponseRedirects('/import/transactions/map');
        $crawler = $this->client->followRedirect();

        return $this->client->submitForm($button, $values + [
            'import_mapping[defaultAccount]' => (string) $this->account->getId(),
            'import_mapping[fallbackCategory]' => (string) $this->fallback->getId(),
        ]);
    }

    public function testTheImportPageRequiresALogin(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/import/transactions');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testTheUploadPageIsLinkedFromTheTransactions(): void
    {
        $this->client->request('GET', '/transaction');

        self::assertSelectorExists('a[href="/import/transactions"]');
        $this->client->request('GET', '/import/transactions');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type="file"]');
    }

    public function testTheColumnsAreRecognisedFromTheirNames(): void
    {
        $this->upload(self::SAMPLE);
        self::assertResponseRedirects('/import/transactions/map');
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.import-sample', '4 colonnes');
        self::assertSame('0', $crawler->filter('select[name="import_mapping[date]"] option[selected]')->attr('value'));
        self::assertSame('1', $crawler->filter('select[name="import_mapping[label]"] option[selected]')->attr('value'));
        self::assertSame('2', $crawler->filter('select[name="import_mapping[amount]"] option[selected]')->attr('value'));
        self::assertSame('3', $crawler->filter('select[name="import_mapping[category]"] option[selected]')->attr('value'));
        self::assertSelectorExists('input[name="import_mapping[skipDuplicates]"][checked]');
        // Le bouton « Importer » n'apparaît qu'après l'aperçu.
        self::assertSelectorNotExists('button[name="import_mapping[import]"]');
    }

    public function testThePreviewCountsValidInvalidAndNewCategoriesWithoutSavingAnything(): void
    {
        $crawler = $this->map(self::SAMPLE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.import-stats .is-ok', '3');
        self::assertSelectorTextContains('.import-stats .is-error', '2');
        self::assertSelectorTextContains('.import-problems', 'Ligne 5');
        self::assertSelectorTextContains('.import-problems', 'Date non reconnue');
        self::assertSelectorTextContains('.import-problems', 'Montant non reconnu');
        self::assertSelectorTextContains('.import-result', 'Revenus');
        self::assertSelectorExists('button[name="import_mapping[import]"]');
        self::assertCount(5, $crawler->filter('.import-preview tbody tr'));
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]), 'L\'aperçu n\'enregistre rien');
    }

    public function testImportingCreatesTheTransactionsWithTheRightSignsAndCategories(): void
    {
        $this->map(self::SAMPLE);
        $this->client->submitForm('Importer 3 transactions');

        self::assertResponseRedirects('/transaction');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', '3 transactions importées');
        self::assertSelectorTextContains('.flash-success', '2 lignes invalides ignorées');
        self::assertSelectorTextContains('.flash-success', '3 catégories créées');

        $transactions = $this->manager->getRepository(Transaction::class)->findBy([], ['date' => 'ASC', 'id' => 'ASC']);
        self::assertCount(3, $transactions);
        $byLabel = array_column(array_map(static fn (Transaction $t): array => [$t->getLabel(), $t], $transactions), 1, 0);
        self::assertSame(TransactionType::Income, $byLabel['Salaire novembre']->getType());
        self::assertSame('2500.00', $byLabel['Salaire novembre']->getAmount());
        self::assertSame(TransactionType::Expense, $byLabel['Loyer']->getType());
        self::assertSame('720.00', $byLabel['Loyer']->getAmount(), 'Le montant est enregistré sans son signe');
        self::assertSame('Logement', $byLabel['Loyer']->getCategory()->getName());
        self::assertSame('2026-11-01', $byLabel['Loyer']->getDate()->format('Y-m-d'));
        self::assertSame($this->account->getId(), $byLabel['Loyer']->getAccount()->getId());
        self::assertNotNull($this->manager->getRepository(Category::class)->findOneBy(['name' => 'Revenus']));
    }

    public function testExistingCategoriesAreMatchedIgnoringCase(): void
    {
        $existing = $this->createCategory('Logement');

        $this->map(self::SAMPLE);
        $this->client->submitForm('Importer 3 transactions');

        self::assertCount(1, $this->manager->getRepository(Category::class)->findBy(['name' => 'Logement']), 'Aucun doublon de catégorie');
        $loyer = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Loyer']);
        self::assertSame($existing->getId(), $loyer->getCategory()->getId());
    }

    public function testWithoutCategoryCreationTheDefaultCategoryIsUsed(): void
    {
        $this->map(self::SAMPLE, ['import_mapping[createCategories]' => false]);
        $this->client->submitForm('Importer 3 transactions', ['import_mapping[createCategories]' => false]);

        self::assertCount(0, $this->manager->getRepository(Category::class)->findBy(['name' => 'Revenus']));
        $salaire = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Salaire novembre']);
        self::assertSame($this->fallback->getId(), $salaire->getCategory()->getId());
    }

    public function testImportingTheSameFileTwiceAddsNothing(): void
    {
        $this->map(self::SAMPLE);
        $this->client->submitForm('Importer 3 transactions');
        self::assertSame(3, $this->manager->getRepository(Transaction::class)->count([]));

        $crawler = $this->map(self::SAMPLE);
        self::assertSelectorTextContains('.import-stats .is-ok', '0');
        self::assertSelectorTextContains('.import-stats .is-dup', '3');
        self::assertCount(0, $crawler->filter('button[name="import_mapping[import]"]'), 'Rien à importer : pas de bouton');
        self::assertSame(3, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testDuplicatesCanBeImportedAnywayAndIdenticalLinesInOneFileAreKept(): void
    {
        $content = "Date;Libellé;Montant\n2026-11-01;Café;-3,00\n2026-11-01;Café;-3,00\n";

        // Deux achats identiques le même jour dans le même fichier : les deux sont importés.
        $this->map($content);
        $this->client->submitForm('Importer 2 transactions');
        self::assertSame(2, $this->manager->getRepository(Transaction::class)->count([]));

        // Même fichier de nouveau, doublons autorisés : ils sont ajoutés.
        $this->map($content, ['import_mapping[skipDuplicates]' => false]);
        self::assertSelectorTextContains('.import-stats .is-dup', '2');
        $this->client->submitForm('Importer 0 transaction', ['import_mapping[skipDuplicates]' => false]);
        self::assertSame(4, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testATypeColumnGivesTheDirectionAndTheAmountIsTakenAsIs(): void
    {
        $content = "Date;Libellé;Type;Montant\n2026-11-02;Prime;Revenu;300,00\n2026-11-03;Resto;Dépense;45,50\n2026-11-04;Autre;??;10,00\n";

        $this->map($content);
        self::assertSelectorTextContains('.import-stats .is-ok', '2');
        self::assertSelectorTextContains('.import-problems', 'Type non reconnu');
        $this->client->submitForm('Importer 2 transactions');

        $prime = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Prime']);
        self::assertSame(TransactionType::Income, $prime->getType());
        $resto = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Resto']);
        self::assertSame(TransactionType::Expense, $resto->getType());
        self::assertSame('45.50', $resto->getAmount());
    }

    public function testAnAccountColumnRoutesEachLineAndRejectsUnknownAccounts(): void
    {
        $this->createAccount('Livret A');
        $content = "Date;Libellé;Montant;Compte\n2026-11-02;Virement;100,00;livret a\n2026-11-03;Achat;-20,00;Compte courant\n2026-11-04;Perdu;-5,00;Compte inexistant\n";

        $this->map($content);
        self::assertSelectorTextContains('.import-stats .is-ok', '2');
        self::assertSelectorTextContains('.import-problems', 'Compte inconnu');
        $this->client->submitForm('Importer 2 transactions');

        $livret = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Virement']);
        self::assertSame('Livret A', $livret->getAccount()->getName());
    }

    public function testAnotherUsersAccountsAreNeverUsed(): void
    {
        $other = $this->createUser('bob@example.test', 'Bob');
        $this->createAccount('Compte de Bob', 'EUR', $other);
        $content = "Date;Libellé;Montant;Compte\n2026-11-02;Intrus;-5,00;Compte de Bob\n";

        $this->map($content);

        self::assertSelectorTextContains('.import-stats .is-error', '1');
        self::assertSelectorTextContains('.import-problems', 'Compte inconnu');
        // Le compte d'un autre utilisateur n'est pas proposé comme destination.
        $options = $this->client->getCrawler()->filter('select[name="import_mapping[defaultAccount]"] option')->each(static fn ($o): string => $o->text());
        self::assertNotContains('Compte de Bob (EUR)', $options);
    }

    public function testBankStyleFilesAreRead(): void
    {
        // Windows-1252, séparateur virgule, dates à la française, montants avec point décimal et milliers.
        $content = mb_convert_encoding("Date,Libell\u{00E9},Montant\n20/11/2026,\"Caf\u{00E9}, croissant\",\"-3.20\"\n21-11-2026,Remboursement,\"1,250.00\"\n", 'Windows-1252', 'UTF-8');

        $this->map($content);
        self::assertSelectorTextContains('.import-stats .is-ok', '2');
        $this->client->submitForm('Importer 2 transactions');

        $cafe = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Café, croissant']);
        self::assertNotNull($cafe, 'Accents et virgule dans une cellule entre guillemets');
        self::assertSame('3.20', $cafe->getAmount());
        $refund = $this->manager->getRepository(Transaction::class)->findOneBy(['label' => 'Remboursement']);
        self::assertSame('1250.00', $refund->getAmount());
        self::assertSame(TransactionType::Income, $refund->getType());
    }

    public function testAFinoraExportCanBeImportedBack(): void
    {
        $other = $this->createCategory('Courses');
        $this->createDatedTransaction($this->account, $other, TransactionType::Expense, '85.40', '2026-11-18', 'Supermarché');
        $this->client->request('GET', '/export/transactions');
        $csv = (string) $this->client->getInternalResponse()->getContent();

        // Dans un autre compte : l'export est relu tel quel, avec les noms de colonnes de FINORA.
        $destination = $this->createAccount('Compte importé');
        $this->upload($csv);
        $crawler = $this->client->followRedirect();
        self::assertSame('0', $crawler->filter('select[name="import_mapping[date]"] option[selected]')->attr('value'));
        self::assertSame('2', $crawler->filter('select[name="import_mapping[type]"] option[selected]')->attr('value'));
        self::assertSame('3', $crawler->filter('select[name="import_mapping[amount]"] option[selected]')->attr('value'));
        self::assertSame('5', $crawler->filter('select[name="import_mapping[account]"] option[selected]')->attr('value'));
        self::assertSame('6', $crawler->filter('select[name="import_mapping[category]"] option[selected]')->attr('value'));

        $this->client->submitForm('Voir l\'aperçu', [
            'import_mapping[defaultAccount]' => (string) $destination->getId(),
            'import_mapping[fallbackCategory]' => (string) $this->fallback->getId(),
        ]);
        // Le compte vient de la colonne « Compte » : c'est le compte d'origine, donc la ligne est un doublon.
        self::assertSelectorTextContains('.import-stats .is-dup', '1');
    }

    public function testRequiredColumnsMustBeChosen(): void
    {
        $this->upload(self::SAMPLE);
        $this->client->followRedirect();

        $this->client->submitForm('Voir l\'aperçu', [
            'import_mapping[date]' => '',
            'import_mapping[defaultAccount]' => (string) $this->account->getId(),
            'import_mapping[fallbackCategory]' => (string) $this->fallback->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Indiquez la colonne correspondante');
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testBadFilesAreRejectedWithAMessage(): void
    {
        foreach (['' => 'vide', "Date;Libellé;Montant\n" => 'en-têtes', "juste une ligne\nsans structure\n" => 'ressemble pas'] as $content => $expected) {
            $this->upload((string) $content);
            self::assertResponseStatusCodeSame(422, $expected);
        }
        self::assertSelectorTextContains('body', 'ressemble pas');
    }

    public function testTheWrongExtensionIsRejected(): void
    {
        $this->upload("Date;Libellé;Montant
2026-11-01;Test;-1,00
", 'releve.exe');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'au format .csv ou .txt');
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testTooManyRowsAreRefused(): void
    {
        $content = "Date;Libellé;Montant\n".str_repeat("2026-11-01;Ligne;-1,00\n", 2001);

        $this->upload($content);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'plus de 2000 lignes');
    }

    public function testWithoutAFileTheMapStepRedirectsToTheUpload(): void
    {
        $this->client->request('GET', '/import/transactions/map');

        self::assertResponseRedirects('/import/transactions');
    }

    public function testCancellingForgetsTheFile(): void
    {
        $this->upload(self::SAMPLE);
        $this->client->followRedirect();
        $this->client->submitForm('Annuler');

        self::assertResponseRedirects('/transaction');
        $this->client->request('GET', '/import/transactions/map');
        self::assertResponseRedirects('/import/transactions');
    }

    public function testFormulaLikeLabelsAreStoredAsPlainTextAndEscapedOnDisplay(): void
    {
        $content = "Date;Libellé;Montant
2026-11-01;\"=1+1 & 5 < 10 > 3\";-5,00
";

        $this->map($content);
        $this->client->submitForm('Importer 1 transaction');

        $crawler = $this->client->request('GET', '/transaction');
        self::assertSame('=1+1 & 5 < 10 > 3', $crawler->filter('.tx-list .name')->text(), 'Le texte est conservé tel quel, sans calcul');
        self::assertStringContainsString('=1+1 &amp; 5 &lt; 10 &gt; 3', (string) $this->client->getResponse()->getContent(), "Et échappé à l'affichage");
    }

    public function testAFileLookingLikeHtmlIsRefused(): void
    {
        $this->upload("Date;Libellé;Montant
2026-11-01;\"<script>alert(1)</script>\";-5,00
");

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(Transaction::class)->count([]));
    }

    public function testImportedLinesAreCountedInTheUsersOwnDataOnly(): void
    {
        $other = $this->createUser('bob@example.test', 'Bob');
        $this->createAccount('Compte de Bob', 'EUR', $other);

        $this->map(self::SAMPLE);
        $this->client->submitForm('Importer 3 transactions');

        foreach ($this->manager->getRepository(Transaction::class)->findAll() as $transaction) {
            self::assertSame($this->user->getId(), $transaction->getOwner()->getId());
        }
    }
}
