<?php

namespace App\Tests\Controller;

final class ThemeTest extends AbstractCrudTestCase
{
    public function testEveryPageAppliesTheChosenThemeBeforeThePageIsShown(): void
    {
        $this->client->request('GET', '/');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertSelectorExists('meta[name="theme-color"]');
        self::assertStringContainsString("localStorage.getItem('finora-theme')", $content);
        // Le script est dans <head>, avant le contenu de la page.
        self::assertLessThan(strpos($content, '<body'), strpos($content, "finora-theme"));
    }

    public function testThePublicPagesAlsoSupportTheTheme(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        foreach (['/login', '/register', '/reset-password'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString("localStorage.getItem('finora-theme')", (string) $this->client->getResponse()->getContent(), $url);
        }
    }

    public function testTheSidebarHasAQuickToggle(): void
    {
        $crawler = $this->client->request('GET', '/');

        $toggle = $crawler->filter('.sidebar-user button.theme-toggle');
        self::assertCount(1, $toggle);
        self::assertSame('theme', $toggle->attr('data-controller'));
        self::assertSame('theme#toggle', $toggle->attr('data-action'));
        self::assertNotSame('', (string) $toggle->attr('aria-label'));
        // Une icône pour chaque sens : lune (vers le sombre) et soleil (vers le clair).
        self::assertCount(1, $toggle->filter('.theme-to-dark svg'));
        self::assertCount(1, $toggle->filter('.theme-to-light svg'));
    }

    public function testTheSettingsPageOffersAutoLightAndDark(): void
    {
        $crawler = $this->client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.appearance h2', 'Apparence');
        $buttons = $crawler->filter('.theme-switch[data-controller="theme"] button');
        self::assertSame(['auto', 'light', 'dark'], $buttons->each(static fn ($b) => $b->attr('data-theme-theme-param')));
        self::assertSame(['Auto', 'Clair', 'Sombre'], $buttons->each(static fn ($b) => trim($b->text())));
        foreach ($buttons as $button) {
            self::assertSame('theme#set', $button->getAttribute('data-action'));
            self::assertTrue($button->hasAttribute('aria-pressed'));
        }
    }

    public function testTheThemeControllerIsRegisteredWithTheAssetMapper(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../../assets/controllers/theme_controller.js');

        self::assertStringContainsString('export default class extends Controller', $js);
        foreach (['set(', 'toggle()', "'finora-theme'", 'prefers-color-scheme'] as $needle) {
            self::assertStringContainsString($needle, $js);
        }
        // Même clé de stockage que le script de la page, sinon le choix ne serait pas réappliqué.
        self::assertStringContainsString("'finora-theme'", (string) file_get_contents(__DIR__.'/../../templates/base.html.twig'));
    }

    public function testTheChartsUseThemeAwareClasses(): void
    {
        $account = $this->createAccount();
        $this->createDatedTransaction($account, $this->createCategory(), \App\Enum\TransactionType::Expense, '50.00', (new \DateTimeImmutable('today'))->format('Y-m-d'));

        $crawler = $this->client->request('GET', '/');

        self::assertGreaterThan(0, $crawler->filter('.chart line.grid')->count());
        self::assertGreaterThan(0, $crawler->filter('.chart circle.ring')->count());
        self::assertCount(1, $crawler->filter('.donut svg circle.donut-track'));
    }
}
