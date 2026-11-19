import { Controller } from '@hotwired/stimulus';

/*
 * Carte à deux faces : un bouton la retourne (rotation 3D, voir motion.css) pour montrer le détail au dos.
 * La face cachée est rendue inerte (`inert`) : ni focus clavier ni lecture par les lecteurs d'écran.
 * Échap ou le bouton « Retour » du dos ramènent à la face avant. Fonctionne avec ou sans animations.
 *
 * Utilisation : data-controller="flip" ; cibles : front, back (les deux faces), button (le bouton de la face avant).
 */
export default class extends Controller {
    static targets = ['front', 'back', 'button'];

    connect() {
        this.setFlipped(false);
        this.onKey = (event) => {
            if (event.key === 'Escape' && this.flipped) {
                this.setFlipped(false);
                this.focusButton();
            }
        };
        this.element.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        this.element.removeEventListener('keydown', this.onKey);
    }

    toggle() {
        this.setFlipped(!this.flipped);
        if (this.flipped) {
            this.backTarget.querySelector('a, button')?.focus({ preventScroll: true });
        } else {
            this.focusButton();
        }
    }

    setFlipped(flipped) {
        this.flipped = flipped;
        this.element.classList.toggle('is-flipped', flipped);
        this.frontTarget.inert = flipped;
        this.backTarget.inert = !flipped;
        this.frontTarget.setAttribute('aria-hidden', String(flipped));
        this.backTarget.setAttribute('aria-hidden', String(!flipped));
        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-expanded', String(flipped));
        }
    }

    focusButton() {
        if (this.hasButtonTarget) {
            this.buttonTarget.focus({ preventScroll: true });
        }
    }
}
