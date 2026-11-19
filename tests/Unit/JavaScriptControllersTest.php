<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Exécute les tests des contrôleurs JavaScript (tests/js, lancés par `node --test`) avec le reste de la suite.
 * Ils tournent sur un faux DOM : ni navigateur ni dépendance npm. Ignorés si Node.js n'est pas installé.
 */
final class JavaScriptControllersTest extends TestCase
{
    public function testTheJavaScriptControllersBehaveAsSpecified(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if (null === $node) {
            self::markTestSkipped('Node.js n\'est pas installé : tests/js non exécutés (`node --test "tests/js/*.test.mjs"`).');
        }

        $version = new Process([$node, '--version']);
        $version->run();
        if ((int) ltrim(trim($version->getOutput()), 'v') < 22) {
            self::markTestSkipped('Node.js 22 ou plus est nécessaire (`node --test` avec motif de fichiers).');
        }

        $process = new Process([$node, '--test', 'tests/js/*.test.mjs'], dirname(__DIR__, 2), null, null, 120);
        $process->run();

        self::assertTrue(
            $process->isSuccessful(),
            "Les tests JavaScript échouent :\n".$process->getOutput().$process->getErrorOutput(),
        );
        // Garde-fou : un motif qui ne trouverait aucun fichier réussirait sans rien tester.
        self::assertMatchesRegularExpression('/ℹ tests (\d+)/u', $process->getOutput());
        preg_match('/ℹ tests (\d+)/u', $process->getOutput(), $count);
        self::assertGreaterThanOrEqual(30, (int) $count[1], 'Moins de tests JavaScript que prévu : fichiers de test non trouvés ?');
    }
}
