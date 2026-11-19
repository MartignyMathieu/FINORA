import { Controller } from '@hotwired/stimulus';
import { fxOn } from '../motion.js';

/*
 * Carte en 3D : elle s'incline vers le pointeur (perspective) et un reflet lumineux suit la souris.
 * Réservé aux appareils avec souris (pas de survol au toucher) et aux animations autorisées.
 *
 * Utilisation : data-controller="tilt" ; options : data-tilt-max-value (degrés, 8 par défaut).
 * Le rendu 3D est dans motion.css (.tilt) : le contrôleur ne fait que fournir l'angle (--rx, --ry) et la position
 * du reflet (--gx, --gy).
 */
export default class extends Controller {
    static values = { max: { type: Number, default: 8 } };

    connect() {
        if (!fxOn() || typeof matchMedia !== 'function' || !matchMedia('(hover: hover) and (pointer: fine)').matches) {
            return;
        }

        this.element.classList.add('tilt');
        this.glare = document.createElement('span');
        this.glare.className = 'tilt-glare';
        this.glare.setAttribute('aria-hidden', 'true');
        this.element.append(this.glare);

        this.onMove = (event) => this.move(event);
        this.onLeave = () => this.reset();
        this.element.addEventListener('pointermove', this.onMove);
        this.element.addEventListener('pointerleave', this.onLeave);
    }

    disconnect() {
        if (!this.glare) {
            return;
        }
        this.element.removeEventListener('pointermove', this.onMove);
        this.element.removeEventListener('pointerleave', this.onLeave);
        cancelAnimationFrame(this.frame);
        this.glare.remove();
        this.reset();
        this.element.classList.remove('tilt');
        this.glare = null;
    }

    move(event) {
        const rect = this.element.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) {
            return;
        }
        const x = (event.clientX - rect.left) / rect.width; // 0 → 1
        const y = (event.clientY - rect.top) / rect.height;

        cancelAnimationFrame(this.frame);
        this.frame = requestAnimationFrame(() => {
            this.element.classList.add('is-tilting');
            const { rx, ry } = tiltAngles(x, y, this.maxValue);
            this.element.style.setProperty('--rx', `${rx.toFixed(2)}deg`);
            this.element.style.setProperty('--ry', `${ry.toFixed(2)}deg`);
            this.element.style.setProperty('--gx', `${(x * 100).toFixed(1)}%`);
            this.element.style.setProperty('--gy', `${(y * 100).toFixed(1)}%`);
        });
    }

    reset() {
        this.element.classList.remove('is-tilting');
        ['--rx', '--ry', '--gx', '--gy'].forEach((name) => this.element.style.removeProperty(name));
    }
}

/**
 * Angles d'inclinaison pour une position du pointeur (x, y de 0 à 1 dans la carte) : le bord le plus proche du
 * pointeur s'éloigne, comme si on appuyait dessus. Centre : à plat.
 */
export function tiltAngles(x, y, max) {
    const clamp = (v) => Math.min(Math.max(v, 0), 1);

    return { rx: -(clamp(y) - 0.5) * 2 * max, ry: (clamp(x) - 0.5) * 2 * max };
}
