<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fous des animations, lus dans les feuilles de style :
 *  - aucune animation ni transition en dehors de [data-fx="on"] (donc rien ne bouge quand les animations sont réduites) ;
 *  - aucun contenu masqué hors de cette condition (la page reste lisible si le JavaScript ne tourne pas) ;
 *  - chaque @keyframes est utilisé, et chaque animation référencée existe.
 */
final class MotionCssTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';
    private const GATE = '[data-fx="on"]';
    private const KEYWORDS = ['infinite', 'both', 'forwards', 'backwards', 'alternate', 'alternate-reverse', 'reverse', 'normal', 'linear',
        'ease', 'ease-in', 'ease-out', 'ease-in-out', 'none', 'running', 'paused', 'initial', 'inherit'];

    private static function css(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::ROOT.'/assets/styles/'.$file));
    }

    /** @return list<array{selector: string, declarations: array<string, string>}> */
    private static function rules(string $css): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);
        $rules = [];
        foreach ($matches as $match) {
            $declarations = [];
            foreach (explode(';', $match[2]) as $declaration) {
                if (str_contains($declaration, ':')) {
                    [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                    $declarations[$property] = $value;
                }
            }
            $rules[] = ['selector' => trim($match[1]), 'declarations' => $declarations];
        }

        return $rules;
    }

    /** Le sélecteur impose-t-il [data-fx="on"] ? (« :not([data-fx="on"]) » ne compte pas : c'est l'inverse.) */
    private static function isGated(string $selector): bool
    {
        return str_contains(str_replace(':not('.self::GATE.')', '', $selector), self::GATE);
    }

    /** @return iterable<string, array{string}> */
    public static function stylesheets(): iterable
    {
        yield 'app.css' => ['app.css'];
        yield 'motion.css' => ['motion.css'];
    }

    /** @dataProvider stylesheets */
    #[\PHPUnit\Framework\Attributes\DataProvider('stylesheets')]
    public function testNoAnimationOrTransitionIsActiveOutsideTheGate(string $file): void
    {
        $offenders = [];
        foreach (self::rules(self::css($file)) as $rule) {
            foreach ($rule['declarations'] as $property => $value) {
                $isMotion = 1 === preg_match('/^(animation|transition)(-|$)/', $property);
                $isOff = 1 === preg_match('/^(none|0s)\b/', $value);
                if ($isMotion && !$isOff && !self::isGated($rule['selector'])) {
                    $offenders[] = sprintf('%s { %s: %s }', $rule['selector'], $property, $value);
                }
            }
        }

        self::assertSame([], $offenders, "Animation ou transition hors de [data-fx=\"on\"] dans $file : elle ne serait pas coupée par « Animations réduites ».");
    }

    public function testNothingIsHiddenOutsideTheGate(): void
    {
        foreach (self::rules(self::css('motion.css')) as $rule) {
            $hides = ('0' === ($rule['declarations']['opacity'] ?? null)) && !str_contains($rule['selector'], ':hover');
            $isReveal = str_contains($rule['selector'], '.reveal');
            if ($isReveal && $hides) {
                self::assertTrue(self::isGated($rule['selector']), $rule['selector'].' masque du contenu sans condition : illisible si le JavaScript est coupé');
            }
        }

        // Au moins un état masqué doit exister (sinon le test ne vérifierait rien).
        self::assertStringContainsString('[data-fx="on"] .reveal-ready .reveal { opacity: 0;', self::css('motion.css'));
    }

    public function testEveryKeyframesIsUsedAndEveryReferencedAnimationExists(): void
    {
        $css = self::css('motion.css');
        preg_match_all('/@keyframes\s+([\w-]+)/', $css, $defined);

        $used = [];
        foreach (self::rules($css) as $rule) {
            foreach (['animation', 'animation-name'] as $property) {
                if (!isset($rule['declarations'][$property])) {
                    continue;
                }
                // Une animation par élément de la liste (séparés par des virgules hors parenthèses), ses mots-clés mis à part.
                $value = (string) preg_replace('/[\w-]+\([^)]*\)/', '', $rule['declarations'][$property]);
                foreach (explode(',', $value) as $item) {
                    foreach (preg_split('/\s+/', trim($item)) ?: [] as $token) {
                        if (1 === preg_match('/^[a-z][\w-]*$/i', $token) && !\in_array($token, self::KEYWORDS, true)) {
                            $used[] = $token;
                        }
                    }
                }
            }
        }

        $defined = $defined[1];
        sort($defined);
        $used = array_values(array_unique($used));
        sort($used);

        self::assertNotSame([], $defined);
        self::assertSame($defined, $used, '@keyframes définis et animations référencées doivent correspondre exactement.');
    }

    public function testTheMotionStylesheetIsLoadedByTheApplication(): void
    {
        self::assertStringContainsString("import './styles/motion.css';", (string) file_get_contents(self::ROOT.'/assets/app.js'));
    }

    public function testTheHeadScriptAndTheModuleAgreeOnTheRules(): void
    {
        $head = (string) file_get_contents(self::ROOT.'/templates/base.html.twig');
        $module = (string) file_get_contents(self::ROOT.'/assets/motion.js');

        // Même clé de stockage, même préférence système, même attribut.
        self::assertStringContainsString("'finora-motion'", $head);
        self::assertStringContainsString("STORAGE_KEY = 'finora-motion'", $module);
        self::assertStringContainsString('prefers-reduced-motion: reduce', $head);
        self::assertStringContainsString('prefers-reduced-motion: reduce', $module);
        self::assertStringContainsString("d.dataset.fx=", $head);
        self::assertStringContainsString("dataset.fx === 'on'", $module);
    }
}
