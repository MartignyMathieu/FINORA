/*
 * Animations : une seule règle pour tout le site.
 *
 * L'attribut `data-fx="on"` sur <html> autorise les animations. Il est posé avant l'affichage par le script de
 * base.html.twig, et tient compte :
 *  - du choix de l'utilisateur (Paramètres → Apparence → Animations : « Complètes » ou « Réduites ») ;
 *  - sinon de la préférence du système « réduire les animations » (prefers-reduced-motion).
 * Toutes les animations et transitions CSS (assets/styles/motion.css) sont sous `[data-fx="on"]` ; les contrôleurs
 * Stimulus qui animent quelque chose testent `fxOn()`. Les interactions elles-mêmes (info-bulles, retournement des
 * cartes, menu d'ajout…) fonctionnent toujours, avec ou sans animation.
 */
export const STORAGE_KEY = 'finora-motion';

/** Vrai si les animations sont autorisées sur la page. */
export function fxOn() {
    return document.documentElement.dataset.fx === 'on';
}

/**
 * Décide si les animations sont actives.
 *
 * @param {string|null} choice     'full' (forcées), 'reduce' (coupées) ou autre / null (selon le système)
 * @param {boolean}     systemReduces vrai si le système demande de réduire les animations
 */
export function resolveFx(choice, systemReduces) {
    if (choice === 'full') {
        return true;
    }
    if (choice === 'reduce') {
        return false;
    }

    return !systemReduces;
}

export function systemReducesMotion() {
    return typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Ralentit la fin : 0 → 1 en décélérant. */
export function easeOutCubic(t) {
    return 1 - Math.pow(1 - Math.min(Math.max(t, 0), 1), 3);
}

/** Valeur intermédiaire d'un compteur : de 0 à `target`, selon la progression `t` (0 à 1). */
export function countAt(target, t) {
    return target * easeOutCubic(t);
}

/** Choix enregistré (« full » / « reduce »), ou null pour « selon le système ». */
export function storedChoice() {
    try {
        const value = localStorage.getItem(STORAGE_KEY);

        return value === 'full' || value === 'reduce' ? value : null;
    } catch (e) {
        return null;
    }
}
