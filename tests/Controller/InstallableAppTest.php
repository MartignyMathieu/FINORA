<?php

namespace App\Tests\Controller;

/** Les pages déclarent le manifeste et les icônes qui permettent d'ajouter FINORA à l'écran d'accueil. */
final class InstallableAppTest extends AbstractCrudTestCase
{
    public function testEveryPageDeclaresTheManifestAndTheAppleIcon(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorExists('link[rel="manifest"][href="/manifest.webmanifest"]');
        self::assertSelectorExists('link[rel="apple-touch-icon"][href="/icons/apple-touch-icon.png"]');
        self::assertSelectorExists('meta[name="apple-mobile-web-app-capable"][content="yes"]');
        self::assertSelectorExists('meta[name="apple-mobile-web-app-title"][content="FINORA"]');
    }

    public function testThePublicPagesAreInstallableToo(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        foreach (['/login', '/register'] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorExists('link[rel="manifest"]', $url);
        }
    }
}
