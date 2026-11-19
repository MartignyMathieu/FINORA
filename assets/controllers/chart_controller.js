import { Controller } from '@hotwired/stimulus';

/*
 * Courbe interactive : en survolant (ou en parcourant au clavier) un mois, un trait vertical et une info-bulle
 * affichent les revenus et les dépenses de ce mois. Fonctionne avec ou sans animations.
 *
 * Le graphique est un SVG généré côté serveur (_line_chart.html.twig) ; chaque mois y a une zone sensible
 * (`hit`) qui porte ses valeurs dans des attributs data-* : le texte est celui du serveur, jamais recomposé ici.
 */
export default class extends Controller {
    static targets = ['hit', 'cursor', 'tip'];

    connect() {
        this.svg = this.element.querySelector('svg');
        this.hide();
    }

    show(event) {
        const hit = event.currentTarget;
        const index = this.hitTargets.indexOf(hit);
        if (index === -1) {
            return;
        }

        this.hitTargets.forEach((other) => other.classList.toggle('is-active', other === hit));
        const x = Number(hit.dataset.cx);
        this.cursorTarget.setAttribute('x1', x);
        this.cursorTarget.setAttribute('x2', x);
        this.cursorTarget.classList.add('is-visible');

        this.tipTarget.replaceChildren(
            line('chart-tip-month', hit.dataset.month),
            line('chart-tip-income', `Revenus : ${hit.dataset.income}`),
            line('chart-tip-expense', `Dépenses : ${hit.dataset.expense}`),
        );
        this.tipTarget.hidden = false;
        this.place(x);
    }

    hide() {
        this.hitTargets.forEach((hit) => hit.classList.remove('is-active'));
        this.cursorTarget.classList.remove('is-visible');
        this.tipTarget.hidden = true;
    }

    /** Place l'info-bulle au-dessus du mois, sans jamais sortir du graphique. */
    place(svgX) {
        const box = this.element.getBoundingClientRect();
        const viewBox = this.svg.viewBox.baseVal;
        const scale = box.width / (viewBox.width || 1);
        const half = this.tipTarget.offsetWidth / 2;
        this.tipTarget.style.left = `${clampTipLeft(svgX * scale, half, box.width)}px`;
    }
}

function line(className, text) {
    const element = document.createElement('span');
    element.className = className;
    element.textContent = text;

    return element;
}

/** Position horizontale de l'info-bulle (centre) bornée pour qu'elle reste entièrement visible. */
export function clampTipLeft(center, halfWidth, containerWidth) {
    return Math.min(Math.max(center, halfWidth), Math.max(containerWidth - halfWidth, halfWidth));
}
