import { Controller } from '@hotwired/stimulus';

/*
 * Masquer / afficher le solde total.
 *
 * Le choix est enregistré dans le navigateur (finora-hide-balance) et appliqué avec l'attribut data-hide-balance de <html> :
 * le script de base.html.twig le réapplique avant le premier affichage, et le CSS (app.css) remplace alors les montants
 * marqués .amt-real par leur masque .amt-mask. Le montant lui-même reste dans la page (les compteurs animés continuent) ;
 * le masque est un vrai remplacement d'affichage, pas un simple flou.
 *
 * Utilisation : un bouton `data-controller="privacy" data-action="privacy#toggle"` (aria-pressed reflète l'état).
 */
const STORAGE_KEY = 'finora-hide-balance';
const CHANGE_EVENT = 'finora:privacy';

export default class extends Controller {
    connect() {
        this.onChange = () => this.refresh();
        window.addEventListener(CHANGE_EVENT, this.onChange);
        this.refresh();
    }

    disconnect() {
        window.removeEventListener(CHANGE_EVENT, this.onChange);
    }

    toggle() {
        const hide = !this.hidden();
        const root = document.documentElement;
        try {
            if (hide) {
                root.dataset.hideBalance = '1';
                localStorage.setItem(STORAGE_KEY, '1');
            } else {
                delete root.dataset.hideBalance;
                localStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) {
            // Stockage indisponible (navigation privée…) : le choix s'applique quand même pour cette page.
        }
        window.dispatchEvent(new Event(CHANGE_EVENT));
    }

    hidden() {
        return document.documentElement.dataset.hideBalance === '1';
    }

    refresh() {
        const hidden = this.hidden();
        this.element.setAttribute('aria-pressed', String(hidden));
        this.element.setAttribute('aria-label', hidden ? 'Afficher le solde total' : 'Masquer le solde total');
        this.element.title = hidden ? 'Afficher le solde total' : 'Masquer le solde total';
    }
}
