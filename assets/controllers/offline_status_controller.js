import { Controller } from '@hotwired/stimulus';

/*
 * Bandeau « hors ligne » en haut de chaque page.
 *
 * Il apparaît quand le navigateur n'a plus de réseau, et quand la page affichée est une copie gardée par le service worker (balise
 * <meta name="finora-cached-at"> ajoutée par public/sw.js) : on indique alors la date de la copie. Les visites Turbo remplacent la page
 * sans la recharger : le bandeau se met à jour à chaque `turbo:load`.
 */
export default class extends Controller {
    static targets = ['message', 'reload'];

    connect() {
        this.onChange = () => this.update();
        window.addEventListener('online', this.onChange);
        window.addEventListener('offline', this.onChange);
        document.addEventListener('turbo:load', this.onChange);
        this.update();
    }

    disconnect() {
        window.removeEventListener('online', this.onChange);
        window.removeEventListener('offline', this.onChange);
        document.removeEventListener('turbo:load', this.onChange);
    }

    update() {
        const cachedAt = document.querySelector('meta[name="finora-cached-at"]')?.getAttribute('content') || '';
        const offline = navigator.onLine === false;
        const isCopy = '' !== cachedAt;

        if (!offline && !isCopy) {
            this.element.hidden = true;

            return;
        }

        let message;
        if (isCopy) {
            const date = new Date(cachedAt);
            const when = '' !== cachedAt && !Number.isNaN(date.getTime())
                ? date.toLocaleString('fr-FR', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
                : 'une consultation précédente';
            message = `${offline ? 'Hors ligne' : 'Copie'} : page enregistrée le ${when}. Les modifications ne sont pas possibles.`;
        } else {
            message = 'Vous êtes hors ligne : les modifications ne sont pas possibles.';
        }

        this.messageTarget.textContent = message;
        // De retour en ligne sur une copie : proposer de recharger la page à jour.
        this.reloadTarget.hidden = offline;
        this.element.hidden = false;
    }

    reload() {
        window.location.reload();
    }
}
