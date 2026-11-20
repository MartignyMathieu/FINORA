<?php

namespace App\Tests\Controller;

/** Masquer le solde total : boutons, montants et masques présents, préférence appliquée avant l'affichage. */
final class PrivacyTest extends AbstractCrudTestCase
{
    public function testTheHomeOffersAToggleOnTheDesktopCardAndTheMobileCard(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('.balance-card button.eye-toggle[data-controller="privacy"][data-action="privacy#toggle"]');
        self::assertSelectorExists('.kpi button.eye-toggle[data-controller="privacy"]');
        // Un bouton affiche l'œil ouvert, l'autre l'œil barré ; le CSS choisit selon l'état.
        self::assertCount(2, $crawler->filter('.eye-toggle .eye-open svg'));
        self::assertCount(2, $crawler->filter('.eye-toggle .eye-closed svg'));
        self::assertSame('Masquer le solde total', $crawler->filter('.eye-toggle')->first()->attr('aria-label'));
    }

    public function testEveryTotalBalanceHasItsMaskNextToTheRealAmount(): void
    {
        $crawler = $this->client->request('GET', '/');

        foreach (['.balance-amount', '.kpi:first-child strong'] as $selector) {
            $box = $crawler->filter($selector)->first();
            self::assertCount(1, $box->filter('.amt-real'), $selector);
            self::assertCount(1, $box->filter('.amt-mask[role="img"][aria-label="Montant masqué"]'), $selector);
            self::assertStringContainsString('€', $box->filter('.amt-real')->text(), $selector);
            // Le masque ne contient aucun chiffre du solde.
            self::assertDoesNotMatchRegularExpression('/\d/', $box->filter('.amt-mask')->text(), $selector);
        }
    }

    public function testOnlyTheTotalBalanceIsMaskedNotTheMonthlyFigures(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertCount(2, $crawler->filter('.amt-mask'), 'Solde total : carte mobile et carte bureau seulement');
    }

    public function testThePreferenceIsAppliedBeforeThePageIsShown(): void
    {
        $this->client->request('GET', '/');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString("localStorage.getItem('finora-hide-balance')", $content);
        self::assertStringContainsString('hideBalance', $content);
        // Le script est dans <head>, avant le contenu de la page : pas d'éclair du montant au chargement.
        self::assertLessThan(strpos($content, '<body'), strpos($content, 'finora-hide-balance'));
    }
}
