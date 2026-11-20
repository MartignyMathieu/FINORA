import { Controller } from '@hotwired/stimulus';
import { askWorker, isSupported } from '../pwa.js';

/*
 * Réglage « Mode hors ligne » (page Paramètres).
 *
 * L'interrupteur active ou coupe la copie locale des pages de consultation (voir public/sw.js). Activé, il demande aussitôt une première
 * copie des pages principales ; « Mettre à jour » la rafraîchit, « Effacer » la supprime. Sans HTTPS (ou localhost), le service worker n'existe
 * pas : l'interrupteur est remplacé par une explication.
 */
const PAGES = ['/', '/account', '/transaction', '/budget', '/goal', '/stats', '/calendar', '/recurring', '/transfer', '/category'];

export default class extends Controller {
    static targets = ['toggle', 'status', 'controls', 'unsupported'];

    async connect() {
        if (!isSupported()) {
            this.controlsTarget.hidden = true;
            this.unsupportedTarget.hidden = false;

            return;
        }
        await this.refresh();
    }

    async toggle() {
        const enabled = this.toggleTarget.checked;
        this.toggleTarget.disabled = true;
        this.statusTarget.textContent = enabled ? 'Copie des pages en cours…' : 'Suppression de la copie…';

        await askWorker({ type: 'offline-mode', enabled });
        if (enabled) {
            await askWorker({ type: 'warm', urls: PAGES });
        }

        this.toggleTarget.disabled = false;
        await this.refresh();
    }

    async warm() {
        this.statusTarget.textContent = 'Mise à jour de la copie…';
        await askWorker({ type: 'warm', urls: PAGES });
        await this.refresh();
    }

    async clear() {
        await askWorker({ type: 'clear' });
        await this.refresh();
    }

    /** Relit l'état du service worker et l'affiche. */
    async refresh() {
        const status = await askWorker({ type: 'status' }, 5000);
        const enabled = Boolean(status?.enabled);
        this.toggleTarget.checked = enabled;

        if (!status) {
            this.statusTarget.textContent = 'Le mode hors ligne se prépare : rechargez la page dans un instant.';
        } else if (!enabled) {
            this.statusTarget.textContent = 'Désactivé : seule la page d\'explication s\'affiche sans connexion.';
        } else {
            const count = status.pages;
            this.statusTarget.textContent = count > 0
                ? `Activé : ${count} page${count > 1 ? 's' : ''} disponible${count > 1 ? 's' : ''} hors ligne.`
                : 'Activé : les pages que vous consultez seront gardées.';
        }
        this.controlsTarget.classList.toggle('is-enabled', enabled);
    }
}
