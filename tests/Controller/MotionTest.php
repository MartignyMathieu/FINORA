<?php

namespace App\Tests\Controller;

use App\Entity\Goal;
use App\Enum\TransactionType;
use App\Service\FinanceStats;

/** Balisage des animations, des éléments interactifs et des éléments 3D (leur comportement est testé en JavaScript, tests/js). */
final class MotionTest extends AbstractCrudTestCase
{
    /** Données du mois en cours : de quoi remplir le donut et la courbe de l'accueil. */
    private function seedCurrentMonth(): void
    {
        $account = $this->createAccount();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->createDatedTransaction($account, $this->createCategory('Logement'), TransactionType::Expense, '500.00', $today, 'Loyer');
        $this->createDatedTransaction($account, $this->createCategory('Alimentation'), TransactionType::Expense, '300.00', $today, 'Courses');
        $this->createDatedTransaction($account, $this->createCategory('Revenus'), TransactionType::Income, '2500.00', $today, 'Salaire');
    }

    private function monthsFromNow(int $months): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('first day of this month'))->modify(sprintf('%+d months', $months));
    }

    // ------------------------------------------------------------------ décision des animations

    public function testTheHeadDecidesTheAnimationsBeforeThePageIsShown(): void
    {
        $this->client->request('GET', '/');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertSelectorExists('meta[name="view-transition"][content="same-origin"]');
        self::assertStringContainsString("localStorage.getItem('finora-motion')", $content);
        self::assertStringContainsString('prefers-reduced-motion: reduce', $content);
        self::assertStringContainsString('d.dataset.fx=', $content);
        self::assertLessThan(strpos($content, '<body'), strpos($content, 'd.dataset.fx='), 'Le script est dans <head>, avant le contenu');
    }

    public function testTheSettingsPageOffersAutoFullAndReducedAnimations(): void
    {
        $crawler = $this->client->request('GET', '/settings');

        $buttons = $crawler->filter('.motion-switch[data-controller="motion"] button');
        self::assertSame(['auto', 'full', 'reduce'], $buttons->each(static fn ($b) => $b->attr('data-motion-choice-param')));
        self::assertSame(['Auto', 'Complètes', 'Réduites'], $buttons->each(static fn ($b) => trim($b->text())));
        self::assertSame(['motion#set', 'motion#set', 'motion#set'], $buttons->each(static fn ($b) => $b->attr('data-action')));
        self::assertSame(3, $buttons->filter('[aria-pressed]')->count());
        self::assertSelectorExists('.motion-switch[role="group"][aria-label]');
    }

    // ------------------------------------------------------------------ apparition et messages

    public function testTheContentAppearsProgressively(): void
    {
        $this->client->request('GET', '/account');

        self::assertSelectorExists('main.content[data-controller="reveal"]');
    }

    public function testFlashMessagesCanBeDismissed(): void
    {
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Enregistrer', ['profile[displayName]' => 'Alicia']);
        $crawler = $this->client->followRedirect();

        $flash = $crawler->filter('.flash-success');
        self::assertCount(1, $flash);
        self::assertSame('flash', $flash->attr('data-controller'));
        self::assertSame('alert', $flash->attr('role'));
        $close = $flash->filter('button.flash-close[data-action="flash#close"]');
        self::assertCount(1, $close);
        self::assertNotSame('', (string) $close->attr('aria-label'));
        // Le texte du message reste lisible tel quel.
        self::assertStringContainsString('profil a été mis à jour', $flash->text());
    }

    public function testPublicPagesAlsoHaveDismissibleFlashMessages(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->request('GET', '/register');
        $this->client->submitForm('Commencer', [
            'registration[displayName]' => 'Bob',
            'registration[email]' => 'bob@example.test',
            'registration[plainPassword][first]' => 'un-mot-de-passe-solide',
            'registration[plainPassword][second]' => 'un-mot-de-passe-solide',
        ]);
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('.flash-success[data-controller="flash"] button.flash-close'));
    }

    // ------------------------------------------------------------------ accueil

    public function testTheHomeCardsTiltInThreeDimensionsAndCountUp(): void
    {
        $this->seedCurrentMonth();
        $crawler = $this->client->request('GET', '/');

        self::assertCount(4, $crawler->filter('.kpis .kpi[data-controller="tilt"]'));
        self::assertCount(1, $crawler->filter('.balance-card[data-controller="tilt"]'));

        $amounts = $crawler->filter('.kpi [data-controller="countup"]');
        self::assertCount(4, $amounts);
        foreach ($amounts as $node) {
            self::assertSame('EUR', $node->getAttribute('data-countup-currency-value'));
            // La valeur à atteindre est un nombre décimal avec point, et le texte du serveur reste affiché tel quel (sans JavaScript).
            self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $node->getAttribute('data-countup-value-value'));
            self::assertMatchesRegularExpression('/€$/u', trim($node->textContent));
        }

        // Revenus 2 500, dépenses 800.
        $byLabel = [];
        foreach ($crawler->filter('.kpi') as $card) {
            $byLabel[trim($card->getElementsByTagName('small')->item(0)->textContent)] = $card->getElementsByTagName('strong')->item(0);
        }
        self::assertSame('2500.00', $byLabel['Revenus']->getAttribute('data-countup-value-value'));
        self::assertSame('800.00', $byLabel['Dépenses']->getAttribute('data-countup-value-value'));
        self::assertSame("2\u{202F}500,00\u{00A0}€", trim($byLabel['Revenus']->textContent));
    }

    public function testTheLineChartIsInteractiveWithOneZonePerMonth(): void
    {
        $this->seedCurrentMonth();
        $crawler = $this->client->request('GET', '/');

        $chart = $crawler->filter('.chart[data-controller="chart"]');
        self::assertCount(1, $chart);
        self::assertSame('pointerleave->chart#hide', $chart->attr('data-action'));

        $hits = $chart->filter('rect.chart-hit[data-chart-target="hit"]');
        self::assertCount(6, $hits);
        foreach ($hits as $hit) {
            self::assertNotSame('', $hit->getAttribute('data-month'));
            self::assertStringEndsWith('€', html_entity_decode($hit->getAttribute('data-income')));
            self::assertStringEndsWith('€', html_entity_decode($hit->getAttribute('data-expense')));
            self::assertSame('0', $hit->getAttribute('tabindex'), 'Accessible au clavier');
            self::assertStringContainsString('revenus', $hit->getAttribute('aria-label'));
            self::assertStringContainsString('pointerenter->chart#show', $hit->getAttribute('data-action'));
            self::assertStringContainsString('focus->chart#show', $hit->getAttribute('data-action'));
        }
        // Le mois en cours (dernier) porte les valeurs du jeu de données.
        self::assertSame("2\u{202F}500,00\u{00A0}€", $hits->last()->attr('data-income'));
        self::assertSame("800,00\u{00A0}€", $hits->last()->attr('data-expense'));

        self::assertCount(1, $chart->filter('line.chart-cursor[data-chart-target="cursor"]'));
        self::assertCount(1, $chart->filter('.chart-tip[data-chart-target="tip"][hidden]'));
        // Les courbes se tracent progressivement : pathLength normalise la longueur du trait.
        // (Comparé sur le HTML : selon l'analyseur, la casse de l'attribut SVG « pathLength » n'est pas celle du sélecteur CSS.)
        self::assertCount(2, $chart->filter('polyline'));
        self::assertSame(2, substr_count($chart->html(), 'pathLength="1"'));
        self::assertCount(12, $chart->filter('circle.ring[style*="--i"]'));
    }

    public function testTheChartZonesTileTheWholePlotWithoutGapsOrOverlaps(): void
    {
        $stats = static::getContainer()->get(FinanceStats::class);
        $months = [];
        for ($i = 5; $i >= 0; --$i) {
            $months[] = ['month' => $this->monthsFromNow(-$i), 'income' => 1000.0 + $i, 'expense' => 500.0 + $i];
        }

        $chart = $stats->lineChart($months);

        $zones = $chart['labels'];
        self::assertCount(6, $zones);
        self::assertEqualsWithDelta($chart['left'], $zones[0]['hitX'], 0.11);
        for ($i = 0; $i < 5; ++$i) {
            self::assertEqualsWithDelta($zones[$i + 1]['hitX'], $zones[$i]['hitX'] + $zones[$i]['hitWidth'], 0.11, "Zone $i et zone suivante jointives");
        }
        self::assertEqualsWithDelta($chart['right'], $zones[5]['hitX'] + $zones[5]['hitWidth'], 0.11);
        foreach ($zones as $i => $zone) {
            self::assertGreaterThan($zone['hitX'], $zone['x'], "Le point $i est dans sa zone");
            self::assertLessThan($zone['hitX'] + $zone['hitWidth'], $zone['x']);
            self::assertSame(1000.0 + (5 - $i), $zone['income']);
            self::assertSame(500.0 + (5 - $i), $zone['expense']);
        }
        self::assertArrayHasKey('top', $chart);
    }

    public function testTheDonutAndItsLegendHighlightEachOther(): void
    {
        $this->seedCurrentMonth();
        $crawler = $this->client->request('GET', '/');

        $wrap = $crawler->filter('.donut-wrap[data-controller="donut"]');
        self::assertCount(1, $wrap);

        $segments = $wrap->filter('circle.donut-seg[data-donut-target="segment"]');
        $items = $wrap->filter('.donut-legend li[data-donut-target="item"]');
        self::assertCount(2, $segments);
        self::assertCount(2, $items);

        foreach ($segments as $i => $segment) {
            $item = $items->eq($i);
            self::assertSame((string) $i, $segment->getAttribute('data-index'));
            self::assertSame((string) $i, $item->attr('data-index'), 'Même ordre dans l\'anneau et dans la légende');
            self::assertSame($segment->getAttribute('data-label'), $item->attr('data-label'));
            self::assertSame($segment->getAttribute('data-amount'), $item->attr('data-amount'));
            self::assertSame('0', $item->attr('tabindex'), 'Légende accessible au clavier');
            self::assertStringContainsString('focus->donut#enter', (string) $item->attr('data-action'));
            self::assertSame('--i: '.$i, trim((string) $segment->getAttribute('style')), 'Délai d\'apparition de la part');
        }
        self::assertSame('Logement', $segments->eq(0)->attr('data-label'));
        self::assertSame("500,00\u{00A0}€", $segments->eq(0)->attr('data-amount'));

        // Le centre porte le total et l'intitulé, repris par le contrôleur à la sortie du survol.
        self::assertCount(1, $wrap->filter('.donut-center strong[data-donut-target="value"]'));
        self::assertCount(1, $wrap->filter('.donut-center small[data-donut-target="label"]'));
        self::assertSelectorTextContains('.donut-center', "800,00\u{00A0}€");
    }

    public function testTheStatsDonutIsInteractiveToo(): void
    {
        $this->seedCurrentMonth();
        $crawler = $this->client->request('GET', '/stats');

        self::assertCount(1, $crawler->filter('.donut-wrap[data-controller="donut"]'));
        self::assertCount(2, $crawler->filter('.donut-legend li[data-donut-target="item"][tabindex="0"]'));
        self::assertCount(2, $crawler->filter('circle.donut-seg'));
    }

    // ------------------------------------------------------------------ objectifs : carte à deux faces

    public function testGoalCardsFlipToTheirDetails(): void
    {
        $this->user;
        foreach ([
            ['En cours', '1000.00', '300.00', $this->monthsFromNow(3)],
            ['Atteint', '100.00', '100.00', $this->monthsFromNow(2)],
            ['En retard', '800.00', '320.00', $this->monthsFromNow(-2)],
            ['Sans échéance', '500.00', '0.00', null],
        ] as [$name, $target, $saved, $deadline]) {
            $this->manager->persist(
                (new Goal())->setOwner($this->user)->setName($name)->setTargetAmount($target)->setSavedAmount($saved)->setTargetDate($deadline)
            );
        }
        $this->manager->flush();

        $crawler = $this->client->request('GET', '/goal');

        $cards = $crawler->filter('.goal-flip[data-controller="flip"]');
        self::assertCount(4, $cards);
        $card = static fn (string $name) => $cards->reduce(static fn ($c) => str_contains($c->filter('.goal-front h3')->text(), $name))->first();

        foreach ($cards as $i => $node) {
            $one = $cards->eq($i);
            self::assertCount(1, $one->filter('.goal-front[data-flip-target="front"]'));
            self::assertCount(1, $one->filter('.goal-back[data-flip-target="back"][aria-hidden="true"]'), 'Le dos est caché aux lecteurs d\'écran tant que la carte n\'est pas retournée');
            $button = $one->filter('button.goal-flip-btn[data-flip-target="button"][data-action="flip#toggle"]');
            self::assertCount(1, $button);
            self::assertSame('false', $button->attr('aria-expanded'));
            self::assertStringContainsString('Voir le détail', (string) $button->attr('aria-label'));
            self::assertCount(1, $one->filter('.goal-back button[data-action="flip#toggle"]'), 'Bouton « Retour »');
            self::assertCount(1, $one->filter('.goal-back a[href^="/goal/"]:not([href$="/edit"])'));
            self::assertCount(1, $one->filter('.goal-back a[href$="/edit"]'));
        }

        $ongoing = $card('En cours')->filter('.goal-back')->text();
        self::assertStringContainsString("700,00\u{00A0}€", $ongoing, 'Reste à épargner');
        self::assertStringContainsString('Dans 3 mois', $ongoing);
        self::assertStringContainsString("233,33\u{00A0}€ / mois", $ongoing, '700 € sur 3 mois');

        $reached = $card('Atteint')->filter('.goal-back')->text();
        self::assertStringContainsString('Objectif atteint', $reached);
        self::assertStringNotContainsString('Reste à épargner', $reached);
        self::assertStringNotContainsString('Effort nécessaire', $reached);

        $late = $card('En retard')->filter('.goal-back')->text();
        self::assertStringContainsString('dépassée', $late);
        self::assertStringNotContainsString('Effort nécessaire', $late, 'Plus de calcul d\'effort quand l\'échéance est passée');

        $open = $card('Sans échéance')->filter('.goal-back')->text();
        self::assertStringContainsString('Aucune', $open);
        self::assertStringNotContainsString('Effort nécessaire', $open);
    }

    public function testTheGoalFrontKeepsItsNavigationAndProgress(): void
    {
        $goal = $this->createGoal('Vacances', '2000.00', '500.00');

        $crawler = $this->client->request('GET', '/goal');

        $front = $crawler->filter('.goal-front');
        self::assertSelectorTextContains('.goal-front h3 a', 'Vacances');
        self::assertSame('/goal/'.$goal->getId(), $front->filter('h3 a')->attr('href'));
        self::assertSame('25', $front->filter('[role="progressbar"]')->attr('aria-valuenow'));
        self::assertStringContainsString('Échéance : Août 2027', $front->text());
    }

    // ------------------------------------------------------------------ bouton d'ajout rapide

    public function testTheQuickAddMenuLinksToEveryCreationPage(): void
    {
        $crawler = $this->client->request('GET', '/');

        $fab = $crawler->filter('.fab[data-controller="fab"]');
        self::assertCount(1, $fab);
        self::assertSame(
            ['/account/new', '/goal/new', '/budget/new', '/recurring/new', '/transfer/new', '/transaction/new'],
            $fab->filter('.fab-menu[data-fab-target="menu"] a')->each(static fn ($a) => $a->attr('href')),
        );
        self::assertSame(['0', '1', '2', '3', '4', '5'], array_reverse($fab->filter('.fab-menu li')->each(static fn ($li) => preg_replace('/\D/', '', (string) $li->attr('style')))), 'Le raccourci le plus proche du bouton apparaît en premier');

        $button = $fab->filter('a.fab-main[data-fab-target="button"]');
        self::assertCount(1, $button);
        self::assertSame('/transaction/new', $button->attr('href'), 'Sans JavaScript, le bouton mène à « Nouvelle transaction »');
        self::assertSame('fab#toggle', $button->attr('data-action'));
        self::assertSame('false', $button->attr('aria-expanded'));
        self::assertSame('fab-menu', $button->attr('aria-controls'));
        self::assertCount(1, $fab->filter('#fab-menu'));
        self::assertCount(1, $fab->filter('.fab-backdrop[data-action="click->fab#close"]'));
    }

    public function testTheQuickAddMenuIsAbsentWhereItWouldGetInTheWay(): void
    {
        $account = $this->createAccount();

        foreach (['/transaction/new', "/account/{$account->getId()}/edit", '/profile', '/settings'] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorNotExists('.fab', $url);
        }
        foreach (['/', '/account', '/transaction', '/budget', '/goal', '/calendar', '/stats', '/recurring', '/category'] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorExists('.fab', $url);
        }
    }

    public function testTheQuickAddMenuIsNotShownToVisitors(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        foreach (['/login', '/register', '/reset-password'] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorNotExists('.fab', $url);
        }
    }

    // ------------------------------------------------------------------ pièce 3D

    public function testTheLoginPageShowsADecorativeThreeDimensionalCoin(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        foreach (['/login', '/register'] as $url) {
            $crawler = $this->client->request('GET', $url);

            $scene = $crawler->filter('.auth-brand .coin-scene');
            self::assertCount(1, $scene, $url);
            self::assertSame('true', $scene->attr('aria-hidden'), 'Purement décorative : ignorée des lecteurs d\'écran');
            self::assertCount(1, $scene->filter('.coin-face.coin-front svg'));
            self::assertCount(1, $scene->filter('.coin-face.coin-back svg'));
            self::assertCount(12, $scene->filter('.coin-edge'), 'La tranche : 12 fines couches empilées');
            self::assertSame(range(0, 11), array_map('intval', $scene->filter('.coin-edge')->each(static fn ($e) => preg_replace('/\D/', '', (string) $e->attr('style')))));
            self::assertCount(1, $scene->filter('.coin-shadow'));
            // Le nom reste en texte : la pièce ne remplace pas le titre.
            self::assertSelectorTextContains('.auth-brand', 'FINORA');
        }
    }
}
