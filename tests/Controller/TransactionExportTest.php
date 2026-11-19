<?php

namespace App\Tests\Controller;

use App\Entity\Transaction;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use App\Service\RecurringTransactionGenerator;

final class TransactionExportTest extends AbstractCrudTestCase
{
    private const HEADER = ['Date', 'Libellé', 'Type', 'Montant', 'Devise', 'Compte', 'Catégorie', 'Note', 'Origine'];

    /** Contenu brut de la réponse (le corps d'une réponse en flux est capturé par le client de test). */
    private function body(string $url): string
    {
        $this->client->request('GET', $url);

        return (string) $this->client->getInternalResponse()->getContent();
    }

    /**
     * Lit un CSV comme le ferait un tableur : BOM retiré, champs entre guillemets et sauts de ligne dans une cellule gérés.
     *
     * @return list<list<string>>
     */
    private function rows(string $url = '/export/transactions', string $delimiter = ';'): array
    {
        $body = $this->body($url);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("\xEF\xBB\xBF", $body, 'BOM UTF-8 attendu en tête de fichier');

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($body, 3));
        rewind($stream);
        $rows = [];
        while (false !== ($row = fgetcsv($stream, null, $delimiter, '"', ''))) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    /** @return list<string> Colonne d'un export, sans la ligne d'en-tête. */
    private function column(array $rows, int $index): array
    {
        return array_column(array_slice($rows, 1), $index);
    }

    /** Jeu de données de référence : 4 transactions d'Alice sur trois mois, et une de Bob. */
    private function seed(): void
    {
        $account = $this->createAccount('Compte courant');
        $salary = $this->createCategory('Revenus');
        $housing = $this->createCategory('Logement');
        $food = $this->createCategory('Alimentation');
        $leisure = $this->createCategory('Loisirs');

        // Créées dans le désordre : l'export est trié par date.
        $this->createDatedTransaction($account, $leisure, TransactionType::Expense, '20.00', '2030-03-01', 'Cinéma');
        $this->createDatedTransaction($account, $salary, TransactionType::Income, '2500.00', '2030-01-05', 'Salaire');
        $this->createDatedTransaction($account, $food, TransactionType::Expense, '80.00', '2030-02-10', 'Courses');
        $this->createDatedTransaction($account, $housing, TransactionType::Expense, '500.00', '2030-01-06', 'Loyer');

        $bob = $this->createUser('bob@example.test', 'Bob');
        $this->createDatedTransaction(
            $this->createAccount('Compte de Bob', 'EUR', $bob),
            $this->createCategory('Catégorie de Bob', null, $bob),
            TransactionType::Expense,
            '999.00',
            '2030-01-07',
            'Secret de Bob',
        );
    }

    // ------------------------------------------------------------------ format et en-têtes

    public function testTheResponseIsADownloadableCsvFile(): void
    {
        $this->seed();

        $this->client->request('GET', '/export/transactions');

        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        self::assertSame('text/csv; charset=UTF-8', $headers->get('Content-Type'));
        self::assertSame(
            sprintf('attachment; filename=finora-transactions-%s.csv', (new \DateTimeImmutable('today'))->format('Y-m-d')),
            $headers->get('Content-Disposition'),
        );
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
    }

    public function testTheFrenchFormatIsTheDefault(): void
    {
        $this->seed();

        $body = $this->body('/export/transactions');

        self::assertStringStartsWith("\xEF\xBB\xBFDate;Libellé;Type;Montant;Devise;Compte;Catégorie;Note;Origine\r\n", $body);
        self::assertSame(substr_count($body, "\n"), substr_count($body, "\r\n"), 'Fins de ligne CRLF uniquement');
    }

    public function testRowsAreSortedByDateWithSignedAmountsAndTheirFields(): void
    {
        $this->seed();

        $rows = $this->rows();

        self::assertSame(self::HEADER, $rows[0]);
        self::assertSame([
            ['2030-01-05', 'Salaire', 'Revenu', '2500,00', 'EUR', 'Compte courant', 'Revenus', '', 'Manuelle'],
            ['2030-01-06', 'Loyer', 'Dépense', '-500,00', 'EUR', 'Compte courant', 'Logement', '', 'Manuelle'],
            ['2030-02-10', 'Courses', 'Dépense', '-80,00', 'EUR', 'Compte courant', 'Alimentation', '', 'Manuelle'],
            ['2030-03-01', 'Cinéma', 'Dépense', '-20,00', 'EUR', 'Compte courant', 'Loisirs', '', 'Manuelle'],
        ], array_slice($rows, 1));
    }

    public function testTheInternationalFormatUsesCommasAndDecimalPoints(): void
    {
        $this->seed();

        $rows = $this->rows('/export/transactions?format=intl', ',');

        self::assertSame(self::HEADER, $rows[0]);
        self::assertSame(['2500.00', '-500.00', '-80.00', '-20.00'], $this->column($rows, 3));
    }

    public function testAmountsAreExactStringsNotRoundedFloats(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '0.10', '2030-01-01', 'a');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '1234567890.12', '2030-01-02', 'b');
        $this->createDatedTransaction($account, $category, TransactionType::Income, '0.01', '2030-01-03', 'c');

        self::assertSame(['-0,10', '-1234567890,12', '0,01'], $this->column($this->rows(), 3));
    }

    public function testTheCurrencyColumnFollowsTheAccount(): void
    {
        $category = $this->createCategory();
        $this->createDatedTransaction($this->createAccount('Compte USD', 'USD'), $category, TransactionType::Expense, '12.50', '2030-01-01', 'Achat');
        $this->createDatedTransaction($this->createAccount('Compte EUR', 'EUR'), $category, TransactionType::Expense, '5.00', '2030-01-02', 'Pain');

        $rows = $this->rows();

        self::assertSame(['USD', 'EUR'], $this->column($rows, 4));
        self::assertSame(['Compte USD', 'Compte EUR'], $this->column($rows, 5));
    }

    public function testTheOriginColumnTellsManualAndRecurringTransactionsApart(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '5.00', '2030-01-01', 'Manuelle');
        $rule = $this->createRecurring($account, $category, '2030-01-10', RecurrenceFrequency::Monthly, '50.00', TransactionType::Expense, 'Abonnement');
        static::getContainer()->get(RecurringTransactionGenerator::class)->generateFor($rule, new \DateTimeImmutable('2030-02-20'));

        $rows = $this->rows();

        self::assertSame(['Manuelle', 'Récurrente', 'Récurrente'], $this->column($rows, 8));
    }

    public function testAnEmptyExportStillHasItsHeader(): void
    {
        $rows = $this->rows();

        self::assertSame([self::HEADER], $rows);
    }

    // ------------------------------------------------------------------ volume

    public function testTheExportIsNotLimitedToTheRowsShownInTheList(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        for ($i = 0; $i < 320; ++$i) {
            $this->manager->persist(
                (new Transaction())->setAccount($account)->setCategory($category)->setType(TransactionType::Expense)
                    ->setAmount('1.00')->setLabel('Ligne '.$i)->setDate(new \DateTimeImmutable('2030-01-01'))
            );
        }
        $this->manager->flush();

        $crawler = $this->client->request('GET', '/transaction');
        self::assertCount(300, $crawler->filter('.tx'), 'La liste est plafonnée à 300 lignes');

        self::assertCount(320, array_slice($this->rows(), 1), 'L\'export contient tout');
    }

    // ------------------------------------------------------------------ filtres

    public function testFilterByType(): void
    {
        $this->seed();

        self::assertSame(['Salaire'], $this->column($this->rows('/export/transactions?type=income'), 1));
        self::assertSame(['Loyer', 'Courses', 'Cinéma'], $this->column($this->rows('/export/transactions?type=expense'), 1));
        self::assertCount(4, $this->column($this->rows('/export/transactions?type=all'), 1));
    }

    public function testFilterByTextMatchesTheLabelOrTheCategoryIgnoringCase(): void
    {
        $this->seed();

        self::assertSame(['Courses'], $this->column($this->rows('/export/transactions?q=COUR'), 1));
        self::assertSame(['Loyer'], $this->column($this->rows('/export/transactions?q=logement'), 1));
        self::assertSame([], $this->column($this->rows('/export/transactions?q=introuvable'), 1));
    }

    public function testTextFilterTreatsWildcardsLiterally(): void
    {
        $this->seed();

        self::assertSame([], $this->column($this->rows('/export/transactions?q=%25'), 1));
        self::assertSame([], $this->column($this->rows('/export/transactions?q=_'), 1));
    }

    public function testFilterByPeriodWithInclusiveBounds(): void
    {
        $this->seed();

        self::assertSame(['Courses', 'Cinéma'], $this->column($this->rows('/export/transactions?from=2030-02-01'), 1));
        self::assertSame(['Salaire', 'Loyer'], $this->column($this->rows('/export/transactions?to=2030-01-31'), 1));
        self::assertSame(['Loyer'], $this->column($this->rows('/export/transactions?from=2030-01-06&to=2030-01-06'), 1), 'Bornes incluses');
        self::assertSame(['Salaire', 'Loyer'], $this->column($this->rows('/export/transactions?from=2030-01-05&to=2030-01-06'), 1));
    }

    public function testFiltersCombine(): void
    {
        $this->seed();

        self::assertSame(['Courses', 'Cinéma'], $this->column($this->rows('/export/transactions?type=expense&from=2030-02-01'), 1));
        self::assertSame(['Loyer'], $this->column($this->rows('/export/transactions?type=expense&q=loy&to=2030-02-28'), 1));
    }

    public function testEmptyFilterValuesAreIgnored(): void
    {
        $this->seed();

        self::assertCount(4, $this->column($this->rows('/export/transactions?from=&to=&q='), 1));
    }

    public function testInvalidParametersAreRejected(): void
    {
        $this->seed();

        foreach ([
            'type=virement', 'from=2030-13-45', 'from=pas-une-date', 'to=31/12/2030', 'to=2030-02-31', 'format=xml',
            'from=2030-03-01&to=2030-01-01', 'q='.str_repeat('a', 101),
        ] as $query) {
            $this->client->request('GET', '/export/transactions?'.$query);
            self::assertResponseStatusCodeSame(422, $query);
        }
    }

    // ------------------------------------------------------------------ sécurité

    public function testTheExportRequiresALogin(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->client->request('GET', '/export/transactions');

        self::assertResponseRedirects('/login');
    }

    public function testOnlyTheCurrentUsersTransactionsAreExported(): void
    {
        $this->seed();

        $alice = implode('|', $this->column($this->rows(), 1));
        self::assertStringNotContainsString('Secret de Bob', $alice);
        self::assertStringNotContainsString('Compte de Bob', implode('|', $this->column($this->rows(), 5)));

        $this->client->loginUser($this->manager->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'bob@example.test']));
        $bob = $this->rows();
        self::assertSame(['Secret de Bob'], $this->column($bob, 1));
        self::assertSame(['-999,00'], $this->column($bob, 3));
    }

    public function testSomeoneElsesDataCannotBeReachedWithFilters(): void
    {
        $this->seed();

        self::assertSame([], $this->column($this->rows('/export/transactions?q=secret'), 1));
        self::assertSame([], $this->column($this->rows('/export/transactions?q=Catégorie de Bob'), 1));
    }

    public function testFormulasInUserDataAreNeutralisedInEveryTextColumn(): void
    {
        $account = $this->createAccount('=cmd|calc');
        $category = $this->createCategory('@categorie');
        $evil = $this->createDatedTransaction($account, $category, TransactionType::Expense, '42.50', '2030-01-01', '=1+1');
        $evil->setNote('=HYPERLINK("http://exemple.test","clic")');
        $this->createDatedTransaction($account, $category, TransactionType::Income, '10.00', '2030-01-02', '+33612345678');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '1.00', '2030-01-03', '-2+3');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '1.00', '2030-01-04', "\t=1+1");
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '1.00', '2030-01-05', '  =1+1');
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '1.00', '2030-01-06', 'Libellé=normal');
        $this->manager->flush();

        $rows = $this->rows();

        self::assertSame(["'=1+1", "'+33612345678", "'-2+3", "'\t=1+1", "'  =1+1", 'Libellé=normal'], $this->column($rows, 1));
        self::assertSame("'=HYPERLINK(\"http://exemple.test\",\"clic\")", $rows[1][7]);
        self::assertSame("'=cmd|calc", $rows[1][5], 'Colonne Compte');
        self::assertSame("'@categorie", $rows[1][6], 'Colonne Catégorie');
        // Les montants négatifs sont de vrais nombres : ils ne sont jamais préfixés.
        self::assertSame('-42,50', $rows[1][3]);
        self::assertSame('10,00', $rows[2][3]);
    }

    public function testTextWithSeparatorsQuotesAndNewlinesSurvivesARoundTrip(): void
    {
        $account = $this->createAccount('Compte; "principal"');
        $category = $this->createCategory('Courses, fêtes');
        $tx = $this->createDatedTransaction($account, $category, TransactionType::Expense, '9.99', '2030-01-01', 'Café; "crème", à l\'épicerie');
        $tx->setNote("Ligne 1\nLigne 2 ; avec \"guillemets\"\r\nLigne 3");
        $this->manager->flush();

        $row = $this->rows()[1];

        self::assertSame('Café; "crème", à l\'épicerie', $row[1]);
        self::assertSame('Compte; "principal"', $row[5]);
        self::assertSame('Courses, fêtes', $row[6]);
        self::assertSame("Ligne 1\nLigne 2 ; avec \"guillemets\"\r\nLigne 3", $row[7]);

        $intl = $this->rows('/export/transactions?format=intl', ',')[1];
        self::assertSame('Café; "crème", à l\'épicerie', $intl[1]);
    }

    public function testHtmlInUserDataIsExportedAsIs(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createDatedTransaction($account, $category, TransactionType::Expense, '1.00', '2030-01-01', 'Test <b>HTML</b> & "guillemets"');

        self::assertSame(['Test <b>HTML</b> & "guillemets"'], $this->column($this->rows(), 1));
    }

    // ------------------------------------------------------------------ interface

    public function testTheTransactionsPageLinksToTheExportAndKeepsTheCurrentFilters(): void
    {
        $crawler = $this->client->request('GET', '/transaction');
        $link = $crawler->filter('a[href^="/export/transactions"]');
        self::assertCount(1, $link);
        self::assertSame('/export/transactions', $link->attr('href'));
        self::assertSame('false', $link->attr('data-turbo'));
        self::assertStringContainsString('toutes les transactions', (string) $link->attr('aria-label'));

        $crawler = $this->client->request('GET', '/transaction?type=expense&q=cour');
        $link = $crawler->filter('a[href^="/export/transactions"]');
        self::assertSame('/export/transactions?type=expense&q=cour', $link->attr('href'));
        self::assertStringContainsString('filtrées', (string) $link->attr('aria-label'));
    }

    public function testTheLinkProducesExactlyWhatTheListShows(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/transaction?type=expense');
        $listed = $crawler->filter('.tx .name')->each(static fn ($n) => trim($n->text()));
        $href = (string) $crawler->filter('a[href^="/export/transactions"]')->attr('href');

        $exported = $this->column($this->rows($href), 1);

        self::assertEqualsCanonicalizing($listed, $exported);
    }

    public function testTheProfileOffersTheFullExport(): void
    {
        $crawler = $this->client->request('GET', '/profile');

        $link = $crawler->filter('.export-note a[href="/export/transactions"]');
        self::assertCount(1, $link);
        self::assertSame('false', $link->attr('data-turbo'));
        self::assertStringContainsString('Exporter toutes mes transactions', $link->text());
    }
}
