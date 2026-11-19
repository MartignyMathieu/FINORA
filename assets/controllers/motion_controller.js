import { Controller } from '@hotwired/stimulus';
import { resolveFx, storedChoice, systemReducesMotion, STORAGE_KEY } from '../motion.js';

/*
 * Réglage « Animations » (Paramètres → Apparence) : Auto (selon le système), Complètes, Réduites.
 * Le choix est mémorisé dans le navigateur ; `data-fx` sur <html> est mis à jour tout de suite.
 *
 * Utilisation : un élément `data-controller="motion"` avec des boutons
 * `data-action="motion#set" data-motion-choice-param="auto|full|reduce"`.
 */
const CHANGE_EVENT = 'finora:motion';

export default class MotionController extends Controller {
    /** Choix fait pendant cette session (« full », « reduce » ou null), même si le stockage est indisponible. */
    static sessionChoice = null;

    connect() {
        this.onChange = () => this.refresh();
        window.addEventListener(CHANGE_EVENT, this.onChange);

        this.media = typeof matchMedia === 'function' ? matchMedia('(prefers-reduced-motion: reduce)') : null;
        this.media?.addEventListener('change', this.onChange);

        this.refresh();
    }

    disconnect() {
        window.removeEventListener(CHANGE_EVENT, this.onChange);
        this.media?.removeEventListener('change', this.onChange);
    }

    /** Choisit « auto », « full » ou « reduce » (toute autre valeur vaut « auto »). */
    set({ params: { choice } }) {
        const valid = choice === 'full' || choice === 'reduce' ? choice : null;
        // Gardé en mémoire : si le stockage est bloqué (navigation privée), le choix tient au moins pour la session
        // au lieu d'être défait au prochain rafraîchissement.
        MotionController.sessionChoice = valid;
        try {
            if (valid) {
                localStorage.setItem(STORAGE_KEY, valid);
            } else {
                localStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) {
            // Stockage indisponible : le choix n'est pas mémorisé d'une visite à l'autre.
        }
        this.applyFx(valid);
        window.dispatchEvent(new Event(CHANGE_EVENT));
    }

    applyFx(choice) {
        document.documentElement.dataset.fx = resolveFx(choice, systemReducesMotion()) ? 'on' : 'off';
    }

    refresh() {
        const stored = storedChoice() ?? MotionController.sessionChoice ?? null;
        this.applyFx(stored);

        const chosen = stored ?? 'auto';
        this.element.querySelectorAll('[data-motion-choice-param]').forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.motionChoiceParam === chosen));
        });
    }
}
