<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fous du thème clair / sombre, lus directement dans assets/styles/app.css :
 *  - les deux copies du jeu sombre (automatique selon l'appareil, et choisi à la main) sont identiques ;
 *  - chaque paire texte / fond respecte les contrastes WCAG AA dans les deux thèmes.
 */
final class ThemeContrastTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** @var array<string, string>|null */
    private static ?array $light = null;
    /** @var array<string, string>|null */
    private static ?array $darkAttribute = null;
    /** @var array<string, string>|null */
    private static ?array $darkMedia = null;

    private static function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::ROOT.'/assets/styles/app.css'));
    }

    /** @return array<string, string> */
    private static function tokens(string $block): array
    {
        preg_match_all('/--([\w-]+):\s*([^;]+);/', $block, $matches, PREG_SET_ORDER);

        return array_column(array_map(static fn (array $m): array => [$m[1], trim($m[2])], $matches), 1, 0);
    }

    private static function block(string $pattern): array
    {
        self::assertSame(1, preg_match($pattern, self::css(), $m), 'Bloc de variables introuvable : '.$pattern);

        return self::tokens($m[1]);
    }

    /** @return array<string, string> */
    private static function light(): array
    {
        return self::$light ??= self::block('/:root\s*\{(.*?)\n\}/s');
    }

    /** @return array<string, string> */
    private static function darkAttribute(): array
    {
        return self::$darkAttribute ??= self::block('/:root\[data-theme="dark"\]\s*\{(.*?)\n\}/s');
    }

    /** @return array<string, string> */
    private static function darkMedia(): array
    {
        return self::$darkMedia ??= self::block('/@media \(prefers-color-scheme: dark\)\s*\{\s*:root:not\(\[data-theme="light"\]\)\s*\{(.*?)\n    \}/s');
    }

    /** @return array<string, string> Palette complète d'un thème (le sombre complète le clair). */
    private static function palette(string $theme): array
    {
        return 'dark' === $theme ? array_merge(self::light(), self::darkAttribute()) : self::light();
    }

    private static function luminance(string $hex): float
    {
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $hex, 'Couleur hexadécimale à 6 chiffres attendue');
        $channels = array_map(static function (string $c): float {
            $v = hexdec($c) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function contrast(string $a, string $b): float
    {
        [$high, $low] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($high + 0.05) / ($low + 0.05);
    }

    public function testTheTwoDarkPalettesAreIdentical(): void
    {
        self::assertNotSame([], self::darkAttribute());
        self::assertSame(self::darkAttribute(), self::darkMedia(), 'Le jeu sombre automatique (@media) et le jeu choisi à la main ([data-theme="dark"]) ont divergé.');
    }

    public function testTheDarkPaletteOnlyOverridesKnownTokens(): void
    {
        foreach (array_keys(self::darkAttribute()) as $token) {
            if ('color-scheme' === $token) {
                continue;
            }
            self::assertArrayHasKey($token, self::light(), "--$token est redéfini en sombre mais n'existe pas en clair");
        }
    }

    /** @return iterable<string, array{string, string, string, float}> */
    public static function colorPairs(): iterable
    {
        // [texte, fond, seuil]. 4,5 : texte courant (WCAG AA). 7 : texte principal (AAA).
        $pairs = [
            ['ink', 'white', 7.0], ['ink', 'bg', 7.0], ['ink', 'light', 7.0],
            ['muted', 'white', 4.5], ['muted', 'bg', 4.5], ['muted', 'light', 4.5],
            ['primary-ink', 'white', 4.5], ['primary-ink', 'bg', 4.5], ['primary-ink', 'light', 4.5], ['primary-ink', 'surface-2', 4.5],
            ['on-primary', 'primary', 4.5],
            ['green', 'white', 4.5], ['green', 'green-soft', 4.5], ['green', 'bg', 4.5],
            ['red', 'white', 4.5], ['red', 'red-soft', 4.5], ['red', 'bg', 4.5],
            ['warn-ink', 'warn-soft', 4.5],
            ['chart-text', 'white', 4.5],
        ];
        foreach (['light', 'dark'] as $theme) {
            foreach ($pairs as [$fg, $bg, $min]) {
                yield "$theme : $fg sur $bg" => [$theme, $fg, $bg, $min];
            }
        }
    }

    #[DataProvider('colorPairs')]
    public function testEveryTextColorIsReadableOnItsBackground(string $theme, string $foreground, string $background, float $minimum): void
    {
        $palette = self::palette($theme);
        self::assertArrayHasKey($foreground, $palette);
        self::assertArrayHasKey($background, $palette);

        self::assertGreaterThanOrEqual(
            $minimum,
            self::contrast($palette[$foreground], $palette[$background]),
            sprintf('Contraste insuffisant (%s) : %s %s sur %s %s', $theme, $foreground, $palette[$foreground], $background, $palette[$background]),
        );
    }

    /** @return iterable<string, array{string, string}> Couleurs identiques dans les deux thèmes (non tokenisées). */
    public static function staticPairs(): iterable
    {
        yield 'pastille verte (blanc sur vert)' => ['#ffffff', '#15803d'];
        yield 'pastille rouge (blanc sur rouge)' => ['#ffffff', '#c81e1e'];
        yield 'barre latérale (texte sur bleu nuit)' => ['#cfd9ea', '#0b1f3c'];
    }

    #[DataProvider('staticPairs')]
    public function testFixedColorPairsAreReadable(string $foreground, string $background): void
    {
        self::assertGreaterThanOrEqual(4.5, self::contrast($foreground, $background));
    }

    public function testTheFixedColorsUsedInTheStylesheetAreTheOnesTested(): void
    {
        $css = self::css();

        self::assertStringContainsString('background: #15803d; color: #fff;', $css);
        self::assertStringContainsString('.chip.negative { background: #c81e1e; }', $css);
    }

    public function testTheBrowserThemeColorMatchesTheBackgroundOfEachTheme(): void
    {
        $light = self::light()['bg'];
        $dark = self::darkAttribute()['bg'];

        foreach (['/templates/base.html.twig', '/assets/controllers/theme_controller.js'] as $file) {
            $content = (string) file_get_contents(self::ROOT.$file);
            self::assertStringContainsString($light, $content, $file.' : couleur du thème clair');
            self::assertStringContainsString($dark, $content, $file.' : couleur du thème sombre');
        }
    }

    public function testTheChartsDoNotHardCodeLightOnlyColors(): void
    {
        foreach (['_line_chart.html.twig', '_donut.html.twig'] as $template) {
            $content = (string) file_get_contents(self::ROOT.'/templates/'.$template);
            foreach (['#e6edf7', '#eef3fb', '#3c4f6e', 'stroke="#fff"', 'stroke="#ffffff"'] as $lightOnly) {
                self::assertStringNotContainsString($lightOnly, $content, "$template : $lightOnly ne s'adapte pas au thème sombre");
            }
        }
    }

    public function testTheStylesheetNoLongerHardCodesLightSurfaces(): void
    {
        $css = self::css();
        // Les valeurs de ces surfaces ne doivent apparaître que dans les jeux de variables.
        foreach (['#fafcff', '#fbfdff', '#dbe9ff', '#eef3fb', '#a9b6cb', '#f5c2c2', '#3c4f6e'] as $surface) {
            self::assertSame(1, substr_count($css, $surface), "$surface devrait n'apparaître que dans la définition de sa variable");
        }
    }
}
