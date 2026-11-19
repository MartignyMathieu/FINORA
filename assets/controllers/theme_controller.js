import { Controller } from '@hotwired/stimulus';

/*
 * Thème clair / sombre.
 *
 * - « auto » (aucun choix enregistré) : l'appareil décide, via la requête CSS prefers-color-scheme ;
 * - « light » / « dark » : choix explicite, enregistré dans le navigateur et appliqué avec l'attribut data-theme
 *   de <html> (le script de base.html.twig le réapplique avant le premier affichage).
 *
 * Utilisation : un élément `data-controller="theme"` avec des boutons
 * `data-action="theme#set" data-theme-theme-param="auto|light|dark"` (sélecteur d'apparence, aria-pressed
 * reflète le choix en cours) ou `data-action="theme#toggle"` (bascule simple clair ↔ sombre).
 */
const STORAGE_KEY = 'finora-theme';
const CHANGE_EVENT = 'finora:theme';
const META_COLORS = { light: '#f4f7fc', dark: '#081326' }; // identiques à --bg dans app.css

export default class extends Controller {
    connect() {
        this.onChange = () => this.refresh();
        window.addEventListener(CHANGE_EVENT, this.onChange);

        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.media.addEventListener('change', this.onChange);

        this.refresh();
    }

    disconnect() {
        window.removeEventListener(CHANGE_EVENT, this.onChange);
        this.media?.removeEventListener('change', this.onChange);
    }

    /** Choisit un thème : « auto », « light » ou « dark ». */
    set({ params: { theme } }) {
        this.apply(theme);
    }

    /** Bascule vers l'autre thème que celui affiché. */
    toggle() {
        this.apply(this.resolved() === 'dark' ? 'light' : 'dark');
    }

    apply(theme) {
        const root = document.documentElement;
        // Fondu doux entre les deux thèmes (uniquement si les animations sont autorisées : voir motion.css).
        root.classList.add('theme-switching');
        clearTimeout(this.switching);
        this.switching = setTimeout(() => root.classList.remove('theme-switching'), 450);
        try {
            if (theme === 'light' || theme === 'dark') {
                root.dataset.theme = theme;
                localStorage.setItem(STORAGE_KEY, theme);
            } else {
                delete root.dataset.theme;
                localStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) {
            // Stockage indisponible (navigation privée…) : le thème s'applique quand même pour cette page.
        }
        window.dispatchEvent(new Event(CHANGE_EVENT));
    }

    /** Thème effectivement affiché. */
    resolved() {
        const explicit = document.documentElement.dataset.theme;
        if (explicit === 'light' || explicit === 'dark') {
            return explicit;
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    /** Choix enregistré, « auto » par défaut. */
    chosen() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);

            return stored === 'light' || stored === 'dark' ? stored : 'auto';
        } catch (e) {
            return document.documentElement.dataset.theme ?? 'auto';
        }
    }

    refresh() {
        const chosen = this.chosen();
        this.element.querySelectorAll('[data-theme-theme-param]').forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.themeThemeParam === chosen));
        });

        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.content = META_COLORS[this.resolved()];
        }
    }
}
