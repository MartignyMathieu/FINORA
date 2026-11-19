import { Controller } from '@hotwired/stimulus';

/*
 * Donut interactif : survoler (ou mettre le focus sur) une part de l'anneau ou une ligne de la légende met la
 * catégorie en avant, estompe les autres et affiche son nom et son montant au centre.
 * Fonctionne avec ou sans animations ; le centre retrouve son contenu d'origine à la sortie.
 *
 * Utilisation : data-controller="donut" sur le conteneur, avec
 *  - les parts : data-donut-target="segment" data-index="N" ;
 *  - les lignes de légende : data-donut-target="item" data-index="N" ;
 *  - le centre : data-donut-target="value" (montant) et data-donut-target="label" (intitulé).
 * Chaque part et chaque ligne porte data-label et data-amount (textes du serveur).
 */
export default class extends Controller {
    static targets = ['segment', 'item', 'value', 'label'];

    connect() {
        this.defaults = {
            value: this.hasValueTarget ? this.valueTarget.textContent : '',
            label: this.hasLabelTarget ? this.labelTarget.textContent : '',
        };
    }

    disconnect() {
        this.leave();
    }

    enter(event) {
        const index = event.currentTarget.dataset.index;
        const source = this.segmentTargets.find((segment) => segment.dataset.index === index) ?? event.currentTarget;

        this.element.classList.add('has-active');
        [...this.segmentTargets, ...this.itemTargets].forEach((element) => {
            element.classList.toggle('is-active', element.dataset.index === index);
        });
        if (this.hasValueTarget) {
            this.valueTarget.textContent = source.dataset.amount ?? this.defaults.value;
        }
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = source.dataset.label ?? this.defaults.label;
        }
    }

    leave() {
        this.element.classList.remove('has-active');
        [...this.segmentTargets, ...this.itemTargets].forEach((element) => element.classList.remove('is-active'));
        if (this.hasValueTarget && this.defaults) {
            this.valueTarget.textContent = this.defaults.value;
        }
        if (this.hasLabelTarget && this.defaults) {
            this.labelTarget.textContent = this.defaults.label;
        }
    }
}
