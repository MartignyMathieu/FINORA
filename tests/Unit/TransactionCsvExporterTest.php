<?php

namespace App\Tests\Unit;

use App\Service\TransactionCsvExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Protection contre l'injection de formules : Excel et LibreOffice exécutent une cellule qui commence par = + - @. */
final class TransactionCsvExporterTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function cells(): iterable
    {
        // Dangereux : préfixés d'une apostrophe (la cellule est alors du texte).
        yield 'égal' => ['=1+1', "'=1+1"];
        yield 'fonction' => ['=HYPERLINK("http://exemple.test","clic")', "'=HYPERLINK(\"http://exemple.test\",\"clic\")"];
        yield 'plus' => ['+33612345678', "'+33612345678"];
        yield 'moins' => ['-2+3', "'-2+3"];
        yield 'arobase' => ['@SUM(A1:A9)', "'@SUM(A1:A9)"];
        yield 'tabulation devant' => ["\t=1+1", "'\t=1+1"];
        yield 'retour chariot devant' => ["\r=1+1", "'\r=1+1"];
        yield 'saut de ligne devant' => ["\n=1+1", "'\n=1+1"];
        yield 'espaces devant' => ['  =1+1', "'  =1+1"];
        yield 'moins seul' => ['-', "'-"];

        // Sans danger : inchangés.
        yield 'texte ordinaire' => ['Supermarché', 'Supermarché'];
        yield 'accents' => ['Café crème à l\'épicerie', 'Café crème à l\'épicerie'];
        yield 'égal au milieu' => ['a=b', 'a=b'];
        yield 'plus au milieu' => ['1+1', '1+1'];
        yield 'espaces devant, texte' => ['  normal', '  normal'];
        yield 'déjà une apostrophe' => ["'=1+1", "'=1+1"];
        yield 'chiffre' => ['2026', '2026'];
        yield 'vide' => ['', ''];
        yield 'null' => [null, ''];
    }

    #[DataProvider('cells')]
    public function testFormulaInjectionIsNeutralised(?string $input, string $expected): void
    {
        self::assertSame($expected, TransactionCsvExporter::safe($input));
    }

    public function testTheHeaderIsStable(): void
    {
        self::assertSame(
            ['Date', 'Libellé', 'Type', 'Montant', 'Devise', 'Compte', 'Catégorie', 'Note', 'Origine'],
            TransactionCsvExporter::HEADER,
        );
    }
}
