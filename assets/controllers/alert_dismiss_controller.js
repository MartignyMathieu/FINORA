import { Controller } from '@hotwired/stimulus';

/*
 * Bannière d'alertes fermable.
 *
 * La fermeture est mémorisée dans le navigateur avec l'empreinte des alertes (signature) : la bannière reste cachée tant que
 * rien ne change, et revient dès qu'une alerte apparaît ou change de niveau. Sans stockage (navigation privée…), elle se ferme
 * quand même pour la page en cours.
 *
 * Utilisation : data-controller="alert-dismiss" data-alert-dismiss-signature-value="…" et un bouton
 * data-action="alert-dismiss#dismiss".
 */
const STORAGE_KEY = 'finora-dismissed-alerts';

export default class extends Controller {
    static values = { signature: String };

    connect() {
        if (this.stored() === this.signatureValue) {
            this.element.hidden = true;
        }
    }

    dismiss() {
        this.element.hidden = true;
        try {
            localStorage.setItem(STORAGE_KEY, this.signatureValue);
        } catch (e) {
            // Stockage indisponible : la bannière est seulement fermée pour cette page.
        }
    }

    stored() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }
}
