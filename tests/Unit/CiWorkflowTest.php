<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Le workflow GitHub Actions (.github/workflows/ci.yml) : de quoi détecter un oubli avant qu'il ne casse l'intégration continue. */
final class CiWorkflowTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** @return array<string, mixed> */
    private function workflow(): array
    {
        return Yaml::parseFile(self::ROOT.'/.github/workflows/ci.yml');
    }

    /** @return list<string> toutes les commandes `run` d'un job, bout à bout */
    private function commands(string $job): array
    {
        $commands = [];
        foreach ($this->workflow()['jobs'][$job]['steps'] as $step) {
            if (isset($step['run'])) {
                $commands[] = $step['run'];
            }
        }

        return $commands;
    }

    public function testItRunsOnPushToMainAndOnPullRequests(): void
    {
        $workflow = $this->workflow();
        $triggers = $workflow['on'] ?? $workflow[true];

        self::assertSame(['main'], $triggers['push']['branches']);
        self::assertArrayHasKey('pull_request', $triggers);
        self::assertSame(['contents' => 'read'], $workflow['permissions'], 'Droits minimaux : lecture seule');
        self::assertTrue($workflow['concurrency']['cancel-in-progress']);
    }

    public function testTheTestJobCoversTheMinimumPhpVersionOfTheProject(): void
    {
        $composer = json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        $minimum = ltrim($composer['require']['php'], '>=^~ ');
        $matrix = $this->workflow()['jobs']['tests']['strategy']['matrix']['php'];

        self::assertContains($minimum, $matrix, 'La version minimale exigée par composer.json doit être testée');
        self::assertFalse($this->workflow()['jobs']['tests']['strategy']['fail-fast'], 'Chaque version PHP va jusqu\'au bout');
    }

    public function testTheTestJobUsesPostgresqlLikeTheApplication(): void
    {
        $job = $this->workflow()['jobs']['tests'];

        self::assertStringStartsWith('postgres:16', $job['services']['postgres']['image']);
        self::assertStringContainsString('pdo_pgsql', $job['steps'][1]['with']['extensions']);
        self::assertStringContainsString('intl', $job['steps'][1]['with']['extensions']);
    }

    public function testEveryEnvironmentVariableTheApplicationNeedsIsProvided(): void
    {
        $env = $this->workflow()['jobs']['tests']['env'];

        // .env est vide : sans ces variables, rien ne démarre (voir .env.local.dist).
        foreach (['APP_ENV', 'APP_SECRET', 'DATABASE_URL', 'MESSENGER_TRANSPORT_DSN', 'MAILER_DSN', 'LOCK_DSN', 'DEFAULT_URI'] as $name) {
            self::assertArrayHasKey($name, $env, $name);
        }
        self::assertSame('test', $env['APP_ENV']);
        self::assertSame('null://null', $env['MAILER_DSN'], 'Aucun e-mail réel pendant les tests');
    }

    public function testTheCommandsRunEverythingInTheRightOrder(): void
    {
        $all = implode("\n", $this->commands('tests'));

        foreach ([
            'composer install',
            'lint:yaml config',
            'lint:twig templates',
            'lint:container',
            'doctrine:migrations:migrate --env=test',
            'doctrine:schema:validate --env=test',
            'node --test "tests/js/*.test.mjs"',
            'php bin/phpunit',
        ] as $command) {
            self::assertStringContainsString($command, $all, $command);
        }
        // Les migrations d'abord, les tests ensuite.
        self::assertLessThan(strpos($all, 'php bin/phpunit'), strpos($all, 'doctrine:migrations:migrate'));
        self::assertLessThan(strpos($all, 'doctrine:migrations:migrate'), strpos($all, 'composer install'));
        self::assertStringNotContainsString('--skip-sync', $all, 'Le schéma de la base doit correspondre aux entités');
    }

    public function testTheTestsNeedNodeForTheJavaScriptControllers(): void
    {
        $uses = array_column($this->workflow()['jobs']['tests']['steps'], 'uses');

        self::assertNotEmpty(array_filter($uses, static fn (?string $u): bool => null !== $u && str_starts_with($u, 'actions/setup-node@')));
        self::assertFileExists(self::ROOT.'/tests/Unit/JavaScriptControllersTest.php');
    }

    public function testTheDockerJobBuildsAndStartsTheProductionImageWithoutCapabilities(): void
    {
        $job = $this->workflow()['jobs']['docker'];
        $commands = implode("\n", $this->commands('docker'));

        self::assertSame('finora-ci:latest', $job['steps'][2]['with']['tags']);
        self::assertFalse($job['steps'][2]['with']['push'], 'L\'image de test n\'est publiée nulle part');
        // Même contrainte que l'hébergeur : capacités retirées (voir le Dockerfile).
        self::assertStringContainsString('--cap-drop=ALL', $commands);
        self::assertStringContainsString('/login', $commands);
        self::assertStringContainsString('/manifest.webmanifest', $commands);
        self::assertStringContainsString('docker logs finora', $commands);
    }

    public function testActionsArePinnedToAMajorVersion(): void
    {
        foreach ($this->workflow()['jobs'] as $name => $job) {
            foreach ($job['steps'] as $step) {
                if (isset($step['uses'])) {
                    self::assertMatchesRegularExpression('#^[\w.-]+/[\w.-]+@v\d+$#', $step['uses'], $name.' : '.$step['uses']);
                }
            }
        }
    }

    public function testNoSecretIsWrittenInTheWorkflow(): void
    {
        $content = (string) file_get_contents(self::ROOT.'/.github/workflows/ci.yml');

        self::assertStringNotContainsString('${{ secrets.', $content, 'Aucun secret nécessaire : tout tourne avec des valeurs de test');
        self::assertDoesNotMatchRegularExpression('/(smtp|https?):\/\/[^\s:@]+:[^\s:@]+@(?!127\.0\.0\.1)/', $content);
    }
}
