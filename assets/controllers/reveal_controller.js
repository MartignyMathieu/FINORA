import { Controller } from '@hotwired/stimulus';
import { fxOn } from '../motion.js';

/*
 * Apparition progressive : les cartes et les lignes des listes glissent et apparaissent à mesure qu'on
 * défile, avec un léger décalage entre elles.
 *
 * Amélioration progressive : sans JavaScript, ou si les animations sont coupées, rien n'est masqué. Les classes
 * ne sont posées qu'ici, et retirées une fois l'apparition terminée (pour ne pas gêner les effets de survol).
 */
const TARGETS = '.card, .list > li, .tx-list > li, .goals > *, .quick-actions a, .counts li, .segmented';
const STAGGER_MS = 70;
const MAX_STEPS = 6;
const CLEANUP_MS = 1200;

export default class extends Controller {
    connect() {
        if (!fxOn() || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const candidates = [...this.element.querySelectorAll(TARGETS)];
        // Un élément contenu dans un autre élément animé n'est pas animé séparément.
        this.items = candidates.filter((item) => !candidates.some((other) => other !== item && other.contains(item)));
        if (this.items.length === 0) {
            return;
        }

        this.timers = [];
        this.onBeforeCache = () => this.reset();
        document.addEventListener('turbo:before-cache', this.onBeforeCache);

        this.items.forEach((item) => item.classList.add('reveal'));
        this.element.classList.add('reveal-ready');

        this.observer = new IntersectionObserver((entries) => this.reveal(entries), { threshold: 0.08, rootMargin: '0px 0px -4% 0px' });
        this.items.forEach((item) => this.observer.observe(item));
    }

    disconnect() {
        this.reset();
    }

    reveal(entries) {
        entries.filter((entry) => entry.isIntersecting).forEach((entry, index) => {
            const item = entry.target;
            this.observer.unobserve(item);
            item.style.setProperty('--reveal-delay', `${Math.min(index, MAX_STEPS) * STAGGER_MS}ms`);
            item.classList.add('is-visible');
            // Les classes d'apparition sont retirées ensuite : le survol retrouve ses propres transitions.
            this.timers.push(setTimeout(() => this.clean(item), CLEANUP_MS));
        });
    }

    clean(item) {
        item.classList.remove('reveal', 'is-visible');
        item.style.removeProperty('--reveal-delay');
    }

    /** Remet la page dans son état d'origine (changement de page, mise en cache Turbo, retrait du contrôleur). */
    reset() {
        this.observer?.disconnect();
        this.timers?.forEach(clearTimeout);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        this.items?.forEach((item) => this.clean(item));
        this.element.classList.remove('reveal-ready');
        this.items = [];
    }
}
