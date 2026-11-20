import { Controller } from '@hotwired/stimulus';

/*
 * Sélection de plusieurs lignes d'une liste (transactions) et barre d'actions en lot.
 *
 * Utilisation : data-controller="bulk" sur le conteneur de la liste ; cases à cocher data-bulk-target="item"
 * data-action="bulk#update" ; case « tout sélectionner » data-bulk-target="all" data-action="bulk#toggleAll" ; barre
 * data-bulk-target="bar" avec le compteur data-bulk-target="count" ; boutons d'action (envoi du formulaire).
 *
 * La barre est visible dans le HTML : sans JavaScript, la liste se coche et se valide quand même. Avec JavaScript, elle n'apparaît que
 * lorsqu'au moins une ligne est cochée, la case de la ligne cochée est mise en évidence et le bouton d'ajout rapide est masqué.
 * Un bouton d'action « catégorie » ou « compte » reste désactivé tant que la liste de destination correspondante n'est pas choisie.
 */
export default class extends Controller {
    static targets = ['item', 'all', 'bar', 'count', 'choice', 'apply'];

    connect() {
        this.update();
    }

    disconnect() {
        document.body.classList.remove('has-selection');
    }

    toggleAll() {
        const checked = this.allTarget.checked;
        this.itemTargets.forEach((item) => { item.checked = checked; });
        this.update();
    }

    clear() {
        this.itemTargets.forEach((item) => { item.checked = false; });
        this.update();
    }

    update() {
        const total = this.itemTargets.length;
        const selected = this.itemTargets.filter((item) => item.checked);

        this.itemTargets.forEach((item) => item.closest('li')?.classList.toggle('is-selected', item.checked));

        if (this.hasAllTarget) {
            this.allTarget.checked = total > 0 && selected.length === total;
            this.allTarget.indeterminate = selected.length > 0 && selected.length < total;
        }
        if (this.hasCountTarget) {
            this.countTarget.textContent = selected.length > 1 ? `${selected.length} sélectionnées` : `${selected.length} sélectionnée`;
        }
        if (this.hasBarTarget) {
            this.barTarget.hidden = selected.length === 0;
        }
        document.body.classList.toggle('has-selection', selected.length > 0);
        this.refreshButtons();
    }

    /** Active chaque bouton « Appliquer » seulement quand sa liste de destination est renseignée. */
    refreshButtons() {
        this.applyTargets.forEach((button) => {
            const source = this.choiceTargets.find((choice) => choice.dataset.bulkFor === button.value);
            button.disabled = source ? '' === source.value : false;
        });
    }
}
