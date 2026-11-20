<?php

namespace App\Tests\Controller;

/** Mode hors ligne : fichiers publics du service worker, page de secours, réglage et bandeau dans les pages. */
final class OfflineModeTest extends AbstractCrudTestCase
{
    private const PUBLIC_DIR = __DIR__.'/../../public';

    public function testTheServiceWorkerIsServedFromTheRootSoItsScopeCoversTheWholeApplication(): void
    {
        self::assertFileExists(self::PUBLIC_DIR.'/sw.js');
        $source = (string) file_get_contents(self::PUBLIC_DIR.'/sw.js');

        self::assertStringContainsString("'/offline.html'", $source);
        self::assertStringContainsString('self.addEventListener(\'fetch\'', $source);
        // Pas d'envoi de données ailleurs : le worker ne parle qu'à ce site et aux polices Google.
        self::assertSame(['fonts.googleapis.com', 'fonts.gstatic.com'], $this->fontHosts($source));
        self::assertStringNotContainsString('XMLHttpRequest', $source);
        self::assertStringNotContainsString('sendBeacon', $source);
    }

    /** @return list<string> */
    private function fontHosts(string $source): array
    {
        preg_match("/FONT_HOSTS = \[(.*?)\]/", $source, $match);

        return array_map(static fn (string $host): string => trim($host, " '"), explode(',', $match[1]));
    }

    public function testTheOfflinePageIsSelfContainedAndInFrench(): void
    {
        $page = (string) file_get_contents(self::PUBLIC_DIR.'/offline.html');

        self::assertStringContainsString('lang="fr"', $page);
        self::assertStringContainsString('Vous êtes hors ligne', $page);
        self::assertStringContainsString('Réessayer', $page);
        self::assertStringContainsString('noindex', $page);
        // Elle doit s'afficher sans réseau : aucune ressource externe (feuille de style, script, image, police).
        self::assertDoesNotMatchRegularExpression('#(src|href)=["\']https?://#i', $page);
        self::assertDoesNotMatchRegularExpression('#@import|url\(\s*["\']?https?://#i', $page);
        self::assertDoesNotMatchRegularExpression('#<script[^>]+src=#i', $page);
    }

    public function testTheNeverCachedSectionsOfTheWorkerMatchTheRealRoutes(): void
    {
        $source = (string) file_get_contents(self::PUBLIC_DIR.'/sw.js');
        preg_match("/NEVER_CACHE = \[(.*?)\]/s", $source, $match);
        $never = array_map(static fn (string $p): string => trim($p, " '\n"), explode(',', $match[1]));

        // Le profil (et la sauvegarde), l'authentification, les imports, exports et actions ne doivent jamais être gardés.
        foreach (['/profile', '/login', '/register', '/reset-password', '/import', '/export', '/bulk', '/undo', '/logout'] as $path) {
            self::assertContains($path, $never, $path);
        }
        // Et ces chemins existent bien dans l'application (un changement de route ne doit pas rendre la liste obsolète).
        $routes = static::getContainer()->get('router')->getRouteCollection();
        $paths = array_map(static fn ($route): string => $route->getPath(), $routes->all());
        foreach (['/profile', '/login', '/register', '/reset-password', '/import/transactions', '/export/transactions', '/bulk/transactions', '/logout'] as $path) {
            self::assertTrue([] !== array_filter($paths, static fn (string $p): bool => $p === $path || str_starts_with($p, $path.'/')), $path);
        }
    }

    public function testEveryPageHasTheOfflineBannerReadyAndLoadsTheWorkerRegistration(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('.offline-bar[data-controller="offline-status"][hidden][role="status"]');
        self::assertSelectorExists('.offline-bar [data-offline-status-target="message"]');
        self::assertSelectorExists('.offline-bar button[data-action="offline-status#reload"][hidden]');
        // Le bandeau est le premier élément de la page, avant le menu.
        self::assertSame('offline-bar', explode(' ', (string) $crawler->filter('body')->children()->first()->attr('class'))[0]);
        // pwa.js (enregistrement du service worker) est chargé avec l'application.
        self::assertStringContainsString('pwa', (string) $this->client->getResponse()->getContent());
    }

    public function testThePublicPagesAreCoveredToo(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertSelectorExists('.offline-bar[data-controller="offline-status"]');
    }

    public function testTheSettingsPageOffersTheOfflineSwitchWithItsPrivacyWarning(): void
    {
        $this->client->request('GET', '/settings');

        self::assertSelectorExists('.offline-card[data-controller="offline"]');
        self::assertSelectorExists('.offline-card input[type="checkbox"][data-offline-target="toggle"][data-action="offline#toggle"]');
        self::assertSelectorExists('.offline-card button[data-action="offline#warm"]');
        self::assertSelectorExists('.offline-card button[data-action="offline#clear"]');
        self::assertSelectorTextContains('.offline-card', 'en lecture seule');
        // L'utilisateur est prévenu que des données financières sont stockées sur l'appareil.
        self::assertSelectorTextContains('.offline-warning', 'appareil personnel');
        self::assertSelectorTextContains('.offline-warning', 'effacées');
        self::assertSelectorExists('.offline-card [data-offline-target="unsupported"][hidden]');
    }

    public function testNoPrivateDataIsEverPutInTheStaticFiles(): void
    {
        foreach (['/sw.js', '/offline.html'] as $file) {
            $content = (string) file_get_contents(self::PUBLIC_DIR.$file);
            self::assertStringNotContainsString('alice@example.test', $content);
            self::assertDoesNotMatchRegularExpression('/\b\d+[ ,.]\d{2}\s*€/', $content, $file);
        }
    }
}
