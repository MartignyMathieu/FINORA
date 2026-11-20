<?php

namespace App\Tests\Unit;

use App\Enum\TransactionType;
use App\Service\CsvReader;
use App\Service\CsvValueParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Lecture tolérante des dates, montants et types d'un CSV, et lecture du fichier (encodage, séparateur, limites). */
final class CsvValueParserTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function amounts(): iterable
    {
        yield 'simple' => ['12', '12.00'];
        yield 'virgule décimale' => ['12,5', '12.50'];
        yield 'point décimal' => ['1234.56', '1234.56'];
        yield 'milliers à la française' => ["2\u{202F}500,00", '2500.00'];
        yield 'milliers à l\'anglaise' => ['1,234.56', '1234.56'];
        yield 'milliers à l\'allemande' => ['1.234,56', '1234.56'];
        yield 'espace insécable' => ["1\u{00A0}234,00", '1234.00'];
        yield 'négatif' => ['-42,50', '-42.50'];
        yield 'positif explicite' => ['+12', '12.00'];
        yield 'parenthèses = négatif' => ['(42,50)', '-42.50'];
        yield 'symbole euro' => ['42,50 €', '42.50'];
        yield 'dollar devant' => ['$1,200.00', '1200.00'];
        yield 'code devise' => ['EUR 99.90', '99.90'];
        yield 'milliers sans décimales' => ['1,234', '1234.00'];
        yield 'plusieurs groupes de milliers' => ['1.234.567', '1234567.00'];
        yield 'zéro négatif' => ['-0,00', '0.00'];
        yield 'vide' => ['', null];
        yield 'texte' => ['douze', null];
        yield 'texte collé' => ['12 abc', null];
        yield 'deux signes' => ['--5', null];
        yield 'signe au milieu' => ['5-3', null];
        yield 'trois décimales ambiguës' => ['12,3456', null];
        yield 'groupes mal formés' => ['1.23.456', null];
        yield 'trop long' => ['123456789012345', null];
    }

    #[DataProvider('amounts')]
    public function testAmounts(string $input, ?string $expected): void
    {
        self::assertSame($expected, CsvValueParser::parseAmount($input));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function dates(): iterable
    {
        yield 'ISO' => ['2026-11-20', '2026-11-20'];
        yield 'français' => ['20/11/2026', '2026-11-20'];
        yield 'tirets' => ['20-11-2026', '2026-11-20'];
        yield 'points' => ['20.11.2026', '2026-11-20'];
        yield 'année sur 2 chiffres' => ['20/11/26', '2026-11-20'];
        yield 'slash inversé' => ['2026/11/20', '2026-11-20'];
        yield 'compact' => ['20261120', '2026-11-20'];
        yield 'avec heure' => ['2026-11-20 14:30:00', '2026-11-20'];
        yield 'ISO complet' => ['2026-11-20T14:30:00Z', '2026-11-20'];
        yield 'heure à la française' => ['20/11/2026 14:30', '2026-11-20'];
        yield 'espaces' => ['  2026-11-20  ', '2026-11-20'];
        yield '30 février' => ['2026-02-30', null];
        yield 'mois 13' => ['13/13/2026', null];
        yield 'texte' => ['bientôt', null];
        yield 'vide' => ['', null];
        yield 'année absurde' => ['01/01/1850', null];
    }

    #[DataProvider('dates')]
    public function testDates(string $input, ?string $expected): void
    {
        self::assertSame($expected, CsvValueParser::parseDate($input)?->format('Y-m-d'));
    }

    /** @return iterable<string, array{string, ?TransactionType}> */
    public static function types(): iterable
    {
        yield 'Revenu' => ['Revenu', TransactionType::Income];
        yield 'income' => ['income', TransactionType::Income];
        yield 'Crédit' => ['Crédit', TransactionType::Income];
        yield 'Dépense' => ['Dépense', TransactionType::Expense];
        yield 'EXPENSE' => ['EXPENSE', TransactionType::Expense];
        yield 'Débit' => ['Débit', TransactionType::Expense];
        yield 'inconnu' => ['virement', null];
        yield 'vide' => ['', null];
    }

    #[DataProvider('types')]
    public function testTypes(string $input, ?TransactionType $expected): void
    {
        self::assertSame($expected, CsvValueParser::parseType($input));
    }

    public function testTextIsCleanedAndBounded(): void
    {
        self::assertSame('Café', CsvValueParser::text("  Café\x00\x07  ", 50));
        self::assertSame('Abc', CsvValueParser::text('Abcdef', 3));
        self::assertSame("Ligne1\nLigne2", CsvValueParser::text("Ligne1\nLigne2", 50), 'Un saut de ligne dans une note est conservé');
    }

    public function testTheReaderDetectsTheSeparatorAndStripsTheBom(): void
    {
        $csv = "\xEF\xBB\xBFDate,Libellé,Montant\n2026-11-01,Test,\"-1,50\"\n";

        $result = (new CsvReader())->read($csv);

        self::assertSame(',', $result['delimiter']);
        self::assertSame(['Date', 'Libellé', 'Montant'], $result['header']);
        self::assertSame([['2026-11-01', 'Test', '-1,50']], $result['rows']);
    }

    public function testTheReaderConvertsWindows1252AndHandlesTabsAndPipes(): void
    {
        $latin = mb_convert_encoding("Date\tLibellé\tMontant\n2026-11-01\tCafé\t-2\n", 'Windows-1252', 'UTF-8');
        self::assertSame("\t", (new CsvReader())->read($latin)['delimiter']);
        self::assertSame('Café', (new CsvReader())->read($latin)['rows'][0][1]);

        self::assertSame('|', (new CsvReader())->read("a|b|c\n1|2|3\n")['delimiter']);
    }

    public function testTheReaderSkipsBlankLinesAndPadsShortRows(): void
    {
        $result = (new CsvReader())->read("Date;Libellé;Montant\n\n2026-11-01;Seul\n   \n");

        self::assertSame([['2026-11-01', 'Seul', '']], $result['rows']);
    }

    public function testTheReaderKeepsLineBreaksInsideQuotedCells(): void
    {
        $result = (new CsvReader())->read("Date;Libellé;Note\n2026-11-01;Test;\"ligne 1\nligne 2\"\n");

        self::assertSame("ligne 1\nligne 2", $result['rows'][0][2]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badFiles(): iterable
    {
        yield 'vide' => ['', 'vide'];
        yield 'espaces' => ["  \n \n", 'vide'];
        yield 'un seul en-tête' => ["Date;Libellé;Montant\n", 'ligne d\'en-têtes'];
        yield 'une colonne' => ["juste du texte\nautre ligne\n", 'ne ressemble pas'];
        yield 'trop gros' => [str_repeat('a', CsvReader::MAX_BYTES + 1), '2 Mo'];
        yield 'trop de lignes' => ["a;b\n".str_repeat("1;2\n", CsvReader::MAX_ROWS + 1), 'plus de 2000'];
        yield 'trop de colonnes' => [implode(';', range(1, 31))."\n".implode(';', range(1, 31))."\n", 'Trop de colonnes'];
    }

    #[DataProvider('badFiles')]
    public function testTheReaderRefusesBadFiles(string $content, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($message, '/').'/');

        (new CsvReader())->read($content);
    }

    public function testTheLimitIsExactlyTwoThousandRows(): void
    {
        $content = "a;b\n".str_repeat("1;2\n", CsvReader::MAX_ROWS);

        self::assertCount(CsvReader::MAX_ROWS, (new CsvReader())->read($content)['rows']);
    }
}
