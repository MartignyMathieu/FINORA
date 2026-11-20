<?php

namespace App\Tests\Unit;

use App\Service\BackupExporter;
use App\Service\BackupRestorer;
use App\Service\UserDataPurger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Lecture d'une sauvegarde : format, version, sections et limites (le contenu des lignes est contrôlé à la restauration). */
final class BackupRestorerTest extends TestCase
{
    private function restorer(): BackupRestorer
    {
        return new BackupRestorer($this->createStub(EntityManagerInterface::class), $this->createStub(ValidatorInterface::class), new UserDataPurger($this->createStub(EntityManagerInterface::class)));
    }

    private function document(array $extra = []): string
    {
        return json_encode(['format' => BackupExporter::FORMAT, 'version' => BackupExporter::VERSION] + $extra, \JSON_THROW_ON_ERROR);
    }

    public function testAValidDocumentIsAcceptedAndMissingSectionsAreEmpty(): void
    {
        $data = $this->restorer()->parse($this->document(['accounts' => [['id' => 1, 'name' => 'Courant']]]));

        self::assertCount(1, $data['accounts']);
        foreach (['categories', 'transactions', 'recurring', 'budgets', 'goals', 'transfers'] as $section) {
            self::assertSame([], $data[$section], $section);
        }
    }

    public function testTheBomIsIgnored(): void
    {
        $data = $this->restorer()->parse("\xEF\xBB\xBF".$this->document());

        self::assertSame(BackupExporter::FORMAT, $data['format']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badDocuments(): iterable
    {
        yield 'vide' => ['', 'JSON valide'];
        yield 'texte' => ['bonjour', 'JSON valide'];
        yield 'liste JSON' => ['[1,2,3]', 'pas une sauvegarde FINORA'];
        yield 'nombre' => ['42', 'pas une sauvegarde FINORA'];
        yield 'mauvais format' => ['{"format":"x","version":1}', 'pas une sauvegarde FINORA'];
        yield 'sans version' => ['{"format":"finora-backup"}', 'Version de sauvegarde'];
        yield 'version future' => ['{"format":"finora-backup","version":2}', 'version 1'];
        yield 'version en texte' => ['{"format":"finora-backup","version":"1"}', 'Version de sauvegarde'];
        yield 'section objet' => ['{"format":"finora-backup","version":1,"accounts":{"a":1}}', 'mal formée'];
        yield 'ligne scalaire' => ['{"format":"finora-backup","version":1,"accounts":[1]}', 'Compte n°1'];
        yield 'trop profond' => [str_repeat('[', 40).str_repeat(']', 40), 'JSON valide'];
    }

    #[DataProvider('badDocuments')]
    public function testBadDocumentsAreRefusedWithAClearMessage(string $json, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($message, '/').'/');

        $this->restorer()->parse($json);
    }

    public function testEachSectionIsLimited(): void
    {
        $rows = array_fill(0, 201, ['id' => 1]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('la section « accounts » dépasse 200 lignes');

        $this->restorer()->parse($this->document(['accounts' => $rows]));
    }

    public function testTheLimitIsInclusive(): void
    {
        $data = $this->restorer()->parse($this->document(['accounts' => array_fill(0, 200, ['id' => 1])]));

        self::assertCount(200, $data['accounts']);
    }

    public function testTheFileSizeIsLimited(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('20 Mo');

        $this->restorer()->parse(str_repeat(' ', BackupRestorer::MAX_BYTES + 1));
    }
}
