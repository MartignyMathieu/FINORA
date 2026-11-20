/*
 * Environnement de test des contrôleurs Stimulus, sans navigateur ni dépendance :
 *  - un faux DOM (éléments, classes, attributs, sélecteurs simples, événements) ;
 *  - un Controller Stimulus minimal (cibles et valeurs) ;
 *  - un chargeur qui exécute les vrais fichiers de assets/controllers sur ce faux environnement.
 *
 * Lancer : node --test tests/js
 */
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const ROOT = resolve(import.meta.dirname, '..', '..');

// ---------------------------------------------------------------------------------------------- faux DOM

const camel = (name) => name.replace(/-([a-z])/g, (_, c) => c.toUpperCase());

class ClassList {
    constructor(element) { this.element = element; this.names = new Set(); }
    add(...names) { names.forEach((n) => this.names.add(n)); }
    remove(...names) { names.forEach((n) => this.names.delete(n)); }
    contains(name) { return this.names.has(name); }
    toggle(name, force) {
        const on = force === undefined ? !this.names.has(name) : force;
        on ? this.names.add(name) : this.names.delete(name);

        return on;
    }
    toString() { return [...this.names].join(' '); }
}

export class FakeElement {
    constructor(tag = 'div', attributes = {}, children = []) {
        this.tagName = tag.toUpperCase();
        this.parent = null;
        this.children = [];
        this.attributes = new Map();
        this.dataset = {};
        this.classList = new ClassList(this);
        this.listeners = new Map();
        this.styleProps = new Map();
        this.style = {
            setProperty: (k, v) => this.styleProps.set(k, String(v)),
            removeProperty: (k) => { this.styleProps.delete(k); },
            getPropertyValue: (k) => this.styleProps.get(k) ?? '',
        };
        this.rect = { left: 0, top: 0, width: 0, height: 0, right: 0, bottom: 0 };
        this.offsetWidth = 0;
        this._text = '';
        for (const [name, value] of Object.entries(attributes)) {
            this.setAttribute(name, value);
        }
        children.forEach((child) => this.append(child));
    }

    get className() { return this.classList.toString(); }
    set className(value) { this.classList = new ClassList(this); String(value).split(/\s+/).filter(Boolean).forEach((n) => this.classList.add(n)); }

    get textContent() { return this.children.length ? this.children.map((c) => c.textContent).join('') : this._text; }
    set textContent(value) { this.children.forEach((c) => { c.parent = null; }); this.children = []; this._text = String(value); }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
        if (name.startsWith('data-')) {
            this.dataset[camel(name.slice(5))] = String(value);
        }
        if (name === 'class') {
            this.className = value;
        }
    }
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); }

    append(...nodes) { nodes.forEach((node) => { node.parent = this; this.children.push(node); }); }
    replaceChildren(...nodes) { this.children.forEach((c) => { c.parent = null; }); this.children = []; this._text = ''; this.append(...nodes); }
    remove() {
        if (this.parent) {
            this.parent.children = this.parent.children.filter((c) => c !== this);
            this.parent = null;
        }
        this.removed = true;
    }
    contains(other) {
        for (let node = other; node; node = node.parent) {
            if (node === this) { return true; }
        }

        return false;
    }
    descendants() { return this.children.flatMap((c) => [c, ...c.descendants()]); }
    /** Premier ancêtre (ou l'élément lui-même) correspondant au sélecteur simple (balise, .classe, [attr]). */
    closest(selector) {
        for (let node = this; node; node = node.parent) {
            if (matches(node, selector)) { return node; }
        }

        return null;
    }

    querySelectorAll(selector) { return query(this, selector); }
    querySelector(selector) { return query(this, selector)[0] ?? null; }

    addEventListener(type, handler) { (this.listeners.get(type) ?? this.listeners.set(type, new Set()).get(type)).add(handler); }
    removeEventListener(type, handler) { this.listeners.get(type)?.delete(handler); }
    dispatchEvent(event) {
        event.target ??= this;
        event.currentTarget = this;
        this.listeners.get(event.type)?.forEach((handler) => handler(event));

        return true;
    }
    getBoundingClientRect() { return this.rect; }
    focus() { globalThis.document.activeElement = this; }
}

/** Sélecteurs pris en charge : liste (,), descendant ( ), enfant direct (>), et par élément : balise ou *, .classe, [attr], [attr="v"]. */
function matches(element, compound) {
    const tag = compound.match(/^[a-z*][\w-]*/i)?.[0];
    if (tag && tag !== '*' && element.tagName !== tag.toUpperCase()) { return false; }
    for (const [, name] of compound.matchAll(/\.([\w-]+)/g)) {
        if (!element.classList.contains(name)) { return false; }
    }
    for (const [, name, value] of compound.matchAll(/\[([\w-]+)(?:~?="([^"]*)")?\]/g)) {
        if (!element.hasAttribute(name)) { return false; }
        if (value !== undefined) {
            const actual = element.getAttribute(name);
            if (compound.includes(`[${name}~="`) ? !actual.split(/\s+/).includes(value) : actual !== value) { return false; }
        }
    }

    return true;
}

function query(root, selector) {
    const found = new Set();
    for (const complex of selector.split(',').map((s) => s.trim()).filter(Boolean)) {
        const parts = complex.replace(/\s*>\s*/g, ' > ').split(/\s+/);
        for (const candidate of root.descendants()) {
            if (matchesChain(candidate, parts, root)) { found.add(candidate); }
        }
    }

    return [...found];
}

function matchesChain(element, parts, root) {
    const last = parts.length - 1;
    if (!matches(element, parts[last])) { return false; }
    if (last === 0) { return true; }
    let current = element;
    for (let i = last - 1; i >= 0; i--) {
        if (parts[i] === '>') { continue; }
        const direct = parts[i + 1] === '>';
        let ancestor = current.parent;
        if (direct) {
            if (!ancestor || ancestor === root.parent || !matches(ancestor, parts[i])) { return false; }
            current = ancestor;
            continue;
        }
        while (ancestor && ancestor !== root.parent && !matches(ancestor, parts[i])) { ancestor = ancestor.parent; }
        if (!ancestor || ancestor === root.parent) { return false; }
        current = ancestor;
    }

    return true;
}

export const el = (tag, attributes, children) => new FakeElement(tag, attributes, children);

// ---------------------------------------------------------------------------------------------- navigateur simulé

/** Installe document, window, stockage, matchMedia, rAF, IntersectionObserver… et renvoie de quoi les piloter. */
export function installBrowser({ fx = 'on', systemReducesMotion = false, hover = true, storage = {} } = {}) {
    const documentElement = new FakeElement('html');
    if (fx) { documentElement.dataset.fx = fx; }

    const doc = new FakeElement('document');
    doc.documentElement = documentElement;
    doc.activeElement = null;
    doc.createElement = (tag) => new FakeElement(tag);
    doc.body = new FakeElement('body');
    doc.append(documentElement, doc.body);
    // Les éléments du <head> utiles aux contrôleurs.
    doc.metaThemeColor = new FakeElement('meta', { name: 'theme-color', content: '#f4f7fc' });
    const originalQuery = doc.querySelector.bind(doc);
    doc.querySelector = (selector) => (selector === 'meta[name="theme-color"]' ? doc.metaThemeColor : originalQuery(selector));
    doc.querySelectorAll = (selector) => query(doc, selector);

    const store = new Map(Object.entries(storage));
    const state = { storageBroken: false, frames: [], observers: [], mediaListeners: [], systemReducesMotion, dark: false, hover };
    const localStorage = {
        getItem: (k) => { if (state.storageBroken) { throw new Error('bloqué'); } return store.has(k) ? store.get(k) : null; },
        setItem: (k, v) => { if (state.storageBroken) { throw new Error('bloqué'); } store.set(k, String(v)); },
        removeItem: (k) => { if (state.storageBroken) { throw new Error('bloqué'); } store.delete(k); },
    };

    const windowListeners = new Map();
    Object.assign(globalThis, {
        document: doc,
        window: globalThis,
        localStorage,
        matchMedia: (query) => ({
            get matches() {
                if (query.includes('prefers-reduced-motion')) { return state.systemReducesMotion; }
                if (query.includes('prefers-color-scheme')) { return state.dark; }
                if (query.includes('hover: hover')) { return state.hover; }

                return false;
            },
            addEventListener: (type, fn) => state.mediaListeners.push(fn),
            removeEventListener: (type, fn) => { state.mediaListeners = state.mediaListeners.filter((f) => f !== fn); },
        }),
        requestAnimationFrame: (fn) => { state.frames.push(fn); return state.frames.length; },
        cancelAnimationFrame: (id) => { state.frames[id - 1] = null; },
        addEventListener: (type, fn) => (windowListeners.get(type) ?? windowListeners.set(type, new Set()).get(type)).add(fn),
        removeEventListener: (type, fn) => windowListeners.get(type)?.delete(fn),
        dispatchEvent: (event) => { windowListeners.get(event.type)?.forEach((fn) => fn(event)); return true; },
        Event: class { constructor(type, init = {}) { this.type = type; Object.assign(this, init); this.defaultPrevented = false; } preventDefault() { this.defaultPrevented = true; } },
        IntersectionObserver: class {
            constructor(callback, options) { this.callback = callback; this.options = options; this.targets = new Set(); state.observers.push(this); }
            observe(target) { this.targets.add(target); }
            unobserve(target) { this.targets.delete(target); }
            disconnect() { this.targets.clear(); this.disconnected = true; }
        },
    });
    // Node expose déjà `performance` ; on garde l'horloge de test pour les animations.
    state.now = 0;
    globalThis.performance = { now: () => state.now };

    return {
        document: doc,
        root: documentElement,
        store,
        state,
        /** Exécute les images d'animation en attente, `ms` plus tard. */
        frame(ms) {
            state.now += ms;
            const pending = state.frames.splice(0).filter(Boolean);
            pending.forEach((fn) => fn(state.now));
        },
        /** Fait entrer des éléments dans l'écran pour les observateurs qui les surveillent. */
        intersect(...targets) {
            state.observers.filter((o) => !o.disconnected).forEach((observer) => {
                const entries = targets.filter((t) => observer.targets.has(t)).map((t) => ({ target: t, isIntersecting: true }));
                if (entries.length) { observer.callback(entries, observer); }
            });
        },
        setSystemReducesMotion(value) { state.systemReducesMotion = value; state.mediaListeners.forEach((fn) => fn()); },
    };
}

// ---------------------------------------------------------------------------------------------- Stimulus minimal

const STIMULUS = `
export class Controller {
    constructor(element, identifier) {
        this.element = element;
        this.identifier = identifier;
        const ctor = this.constructor;
        const find = (name) => [element, ...element.descendants()].filter((n) => n.hasAttribute?.('data-' + identifier + '-target')
            && n.getAttribute('data-' + identifier + '-target').split(/\\s+/).includes(name));
        for (const name of ctor.targets ?? []) {
            const cap = name[0].toUpperCase() + name.slice(1);
            Object.defineProperty(this, name + 'Target', { get: () => { const [first] = find(name); if (!first) { throw new Error('Cible manquante : ' + name); } return first; } });
            Object.defineProperty(this, name + 'Targets', { get: () => find(name) });
            Object.defineProperty(this, 'has' + cap + 'Target', { get: () => find(name).length > 0 });
        }
        for (const [name, definition] of Object.entries(ctor.values ?? {})) {
            const type = typeof definition === 'object' ? definition.type : definition;
            const fallback = typeof definition === 'object' ? definition.default : undefined;
            // data-countup-value-value="1" => dataset.countupValueValue (identifiant + nom de la valeur + « Value »).
            // (un identifiant à tiret, comme « alert-dismiss », devient alertDismiss dans le dataset)
            const key = identifier.replace(/-([a-z])/g, (_, c) => c.toUpperCase()) + name[0].toUpperCase() + name.slice(1) + 'Value';
            Object.defineProperty(this, name + 'Value', {
                get: () => {
                    const raw = element.dataset[key];
                    if (raw === undefined) { return fallback ?? (type === Number ? 0 : type === Boolean ? false : ''); }
                    if (type === Number) { return Number(raw); }
                    if (type === Boolean) { return raw !== 'false'; }

                    return raw;
                },
            });
        }
    }
}
`;

const work = mkdtempSync(join(tmpdir(), 'finora-js-'));
writeFileSync(join(work, 'stimulus.mjs'), STIMULUS);
let counter = 0;

/** Charge un vrai contrôleur de assets/controllers (ou un module de assets/) sur le faux environnement. */
export async function loadController(file) {
    const url = (path) => pathToFileURL(path).href;
    const source = readFileSync(join(ROOT, 'assets', file), 'utf8')
        .replace("from '@hotwired/stimulus'", `from '${url(join(work, 'stimulus.mjs'))}'`)
        .replace(/from '\.\.\/motion\.js'/, `from '${url(join(ROOT, 'assets', 'motion.js'))}'`);
    const target = join(work, `controller-${counter++}.mjs`);
    writeFileSync(target, source);

    return import(url(target));
}

/** Crée un contrôleur sur un élément (comme Stimulus le ferait pour data-controller="identifier") et le connecte. */
export function mount(ControllerClass, identifier, element) {
    const controller = new ControllerClass(element, identifier);
    controller.connect?.();

    return controller;
}

export const motionModule = () => import(pathToFileURL(join(ROOT, 'assets', 'motion.js')).href);
