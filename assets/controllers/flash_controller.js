import { Controller } from '@hotwired/stimulus';
import { fxOn } from '../motion.js';

/*
 * Message flash : il s'affiche en glissant, peut être fermé, et les messages de confirmation disparaissent seuls
 * après quelques secondes. Les messages d'erreur restent jusqu'à ce qu'on les ferme.
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 6000 } };

    connect() {
        if (this.element.classList.contains('flash-success') && this.delayValue > 0) {
            this.timer = setTimeout(() => this.close(), this.delayValue);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    close() {
        clearTimeout(this.timer);
        if (!fxOn()) {
            this.element.remove();

            return;
        }
        this.element.classList.add('is-leaving');
        // Sécurité si l'animation de sortie ne se termine pas (onglet en arrière-plan…).
        setTimeout(() => this.element.remove(), 400);
    }
}
