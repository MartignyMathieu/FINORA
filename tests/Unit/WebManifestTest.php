<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Le manifeste de l'application installable et ses icônes (public/). */
final class WebManifestTest extends TestCase
{
    private const PUBLIC_DIR = __DIR__.'/../../public';

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $json = file_get_contents(self::PUBLIC_DIR.'/manifest.webmanifest');

        return json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testTheManifestDescribesAFullscreenApp(): void
    {
        $manifest = $this->manifest();

        self::assertSame('FINORA', $manifest['short_name']);
        self::assertSame('fullscreen', $manifest['display']);
        self::assertSame(['fullscreen', 'standalone', 'minimal-ui'], $manifest['display_override'], 'Repli : plein écran, puis sans barre d’adresse, puis barre minimale');
        self::assertSame('/', $manifest['start_url']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $manifest['theme_color']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $manifest['background_color']);
    }

    public function testEveryDeclaredIconExistsWithItsDeclaredSize(): void
    {
        $purposes = [];
        foreach ($this->manifest()['icons'] as $icon) {
            $file = self::PUBLIC_DIR.$icon['src'];
            self::assertFileExists($file, $icon['src']);

            [$width, $height] = getimagesize($file);
            self::assertSame($icon['sizes'], $width.'x'.$height, $icon['src']);
            $purposes[] = $icon['purpose'];
        }

        // Android exige 192 et 512 px ; une icône « maskable » évite le liseré blanc des icônes découpées.
        self::assertContains('maskable', $purposes);
        self::assertContains('192x192', array_column($this->manifest()['icons'], 'sizes'));
        self::assertContains('512x512', array_column($this->manifest()['icons'], 'sizes'));
    }

    public function testTheAppleTouchIconIsA180PxImage(): void
    {
        [$width, $height] = getimagesize(self::PUBLIC_DIR.'/icons/apple-touch-icon.png');

        self::assertSame([180, 180], [$width, $height]);
    }
}
