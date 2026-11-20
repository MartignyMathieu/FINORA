<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Fichiers de déploiement (Docker, Render) : de quoi détecter une variable oubliée avant la mise en ligne. */
final class DeploymentFilesTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** @return array<string, mixed> */
    private function blueprint(): array
    {
        return Yaml::parseFile(self::ROOT.'/render.yaml');
    }

    public function testTheBlueprintDeclaresADockerWebServiceAndItsDatabase(): void
    {
        $blueprint = $this->blueprint();
        $service = $blueprint['services'][0];

        self::assertSame('docker', $service['runtime']);
        self::assertSame('finora-db', $blueprint['databases'][0]['name']);
        self::assertFileExists(self::ROOT.'/Dockerfile');
    }

    public function testEveryEnvironmentVariableTheAppNeedsInProductionIsProvided(): void
    {
        $keys = array_column($this->blueprint()['services'][0]['envVars'], 'key');

        // .env est vide : toute la configuration vient de l'hébergeur.
        foreach (['APP_ENV', 'APP_SECRET', 'DATABASE_URL', 'MESSENGER_TRANSPORT_DSN', 'LOCK_DSN', 'MAILER_DSN', 'MAILER_FROM', 'DEFAULT_URI'] as $key) {
            self::assertContains($key, $keys, $key);
        }
    }

    public function testSecretsAreNeverWrittenInTheBlueprint(): void
    {
        $vars = array_column($this->blueprint()['services'][0]['envVars'], null, 'key');

        self::assertTrue($vars['APP_SECRET']['generateValue']);
        self::assertArrayNotHasKey('value', $vars['APP_SECRET']);
        self::assertFalse($vars['MAILER_DSN']['sync']);
        self::assertSame('finora-db', $vars['DATABASE_URL']['fromDatabase']['name']);
        self::assertSame('prod', $vars['APP_ENV']['value']);
        self::assertSame('0', $vars['APP_DEBUG']['value']);
    }

    public function testTheHealthCheckTargetsAPublicPage(): void
    {
        $path = $this->blueprint()['services'][0]['healthCheckPath'];
        $security = Yaml::parseFile(self::ROOT.'/config/packages/security.yaml');
        $public = array_filter($security['security']['access_control'], static fn (array $rule): bool => 'PUBLIC_ACCESS' === $rule['roles']);

        self::assertNotEmpty(array_filter($public, static fn (array $rule): bool => 1 === preg_match('#'.$rule['path'].'#', $path)), $path);
    }

    public function testTheContainerStartsWithMigrationsAndABackgroundConsumer(): void
    {
        $entrypoint = (string) file_get_contents(self::ROOT.'/docker/entrypoint.sh');

        self::assertStringContainsString('doctrine:migrations:migrate', $entrypoint);
        self::assertStringContainsString('messenger:consume async scheduler_default', $entrypoint);
        self::assertStringContainsString('PORT', $entrypoint);
    }

    public function testTheServerBinaryDoesNotNeedAnySystemCapability(): void
    {
        // Render retire les capacités du conteneur : un binaire qui en réclame une ne démarre plus (« Operation not permitted »).
        self::assertStringContainsString('setcap -r /usr/local/bin/frankenphp', (string) file_get_contents(self::ROOT.'/Dockerfile'));
    }

    public function testDevelopmentFilesStayOutOfTheImage(): void
    {
        $ignored = (string) file_get_contents(self::ROOT.'/.dockerignore');

        foreach (['.env.local', '/vendor', '/var', '.git'] as $entry) {
            self::assertStringContainsString($entry, $ignored, $entry);
        }
    }
}
