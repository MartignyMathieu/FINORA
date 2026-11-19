import { Controller } from '@hotwired/stimulus';
import { fxOn, countAt } from '../motion.js';

/*
 * Compteur animé : les montants montent de 0 à leur valeur quand ils apparaissent à l'écran.
 * Le texte affiché par le serveur reste la référence : il est remis à l'identique à la fin de l'animation
 * (et c'est lui que lisent les lecteurs d'écran et les moteurs de recherche).
 *
 * Utilisation : <strong data-controller="countup" data-countup-value-value="1250.5" data-countup-currency-value="EUR">1 250,50 €</strong>
 * (sans `currency`, le nombre est affiché sans symbole ; `suffix` ajoute un texte, par exemple « % »).
 */
export default class extends Controller {
    static values = {
        value: Number,
        currency: String,
        suffix: String,
        decimals: { type: Number, default: 2 },
        duration: { type: Number, default: 900 },
    };

    connect() {
        this.final = this.element.textContent;
        if (!fxOn() || typeof IntersectionObserver === 'undefined' || !Number.isFinite(this.valueValue)) {
            return;
        }

        this.observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                this.observer.disconnect();
                this.run();
            }
        }, { threshold: 0.3 });
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
        if (this.frame) {
            cancelAnimationFrame(this.frame);
        }
        // Jamais de valeur intermédiaire laissée à l'écran (changement de page en cours d'animation).
        if (this.final !== undefined) {
            this.element.textContent = this.final;
        }
    }

    run() {
        const start = performance.now();
        const tick = (now) => {
            const progress = (now - start) / this.durationValue;
            if (progress >= 1) {
                this.element.textContent = this.final;
                this.frame = null;

                return;
            }
            this.element.textContent = this.format(countAt(this.valueValue, progress));
            this.frame = requestAnimationFrame(tick);
        };
        this.frame = requestAnimationFrame(tick);
    }

    format(value) {
        const options = { minimumFractionDigits: this.decimalsValue, maximumFractionDigits: this.decimalsValue };
        if (this.currencyValue) {
            Object.assign(options, { style: 'currency', currency: this.currencyValue });
        }

        return new Intl.NumberFormat('fr-FR', options).format(value) + (this.suffixValue || '');
    }
}
