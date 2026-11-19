import { Controller } from '@hotwired/stimulus';

/*
 * Bouton d'ajout rapide (en bas à droite) : il déploie un menu de raccourcis (transaction, récurrence, budget,
 * objectif, compte). Sans JavaScript, le bouton principal est un simple lien vers « Nouvelle transaction ».
 *
 * Clavier et accessibilité : aria-expanded sur le bouton, menu inerte quand il est fermé, Échap ferme et rend le
 * focus au bouton, un clic ailleurs ferme.
 */
export default class extends Controller {
    static targets = ['button', 'menu'];

    connect() {
        // Avec JavaScript, le lien de secours devient un vrai bouton.
        this.buttonTarget.setAttribute('role', 'button');
        this.setOpen(false);
        this.onOutside = (event) => {
            if (this.open && !this.element.contains(event.target)) {
                this.setOpen(false);
            }
        };
        this.onKey = (event) => {
            if (event.key === 'Escape' && this.open) {
                this.setOpen(false);
                this.buttonTarget.focus({ preventScroll: true });
            }
        };
        document.addEventListener('click', this.onOutside);
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('click', this.onOutside);
        document.removeEventListener('keydown', this.onKey);
    }

    toggle(event) {
        event.preventDefault(); // le lien de secours (sans JavaScript) ne doit pas être suivi
        this.setOpen(!this.open);
        if (this.open) {
            this.menuTarget.querySelector('a')?.focus({ preventScroll: true });
        }
    }

    close() {
        this.setOpen(false);
    }

    setOpen(open) {
        this.open = open;
        this.element.classList.toggle('is-open', open);
        this.buttonTarget.setAttribute('aria-expanded', String(open));
        this.buttonTarget.setAttribute('aria-label', open ? 'Fermer le menu d\'ajout' : 'Ajouter');
        this.menuTarget.inert = !open;
    }
}
