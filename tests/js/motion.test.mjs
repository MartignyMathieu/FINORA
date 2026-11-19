import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installBrowser, loadController, motionModule, mount, el } from './support.mjs';

// ---------------------------------------------------------------------------------------------- assets/motion.js

test('resolveFx : le choix de l\'utilisateur prime sur le système, sinon on suit le système', async () => {
    const { resolveFx } = await motionModule();

    assert.equal(resolveFx('full', true), true, '« Complètes » force les animations même si le système demande de les réduire');
    assert.equal(resolveFx('full', false), true);
    assert.equal(resolveFx('reduce', false), false, '« Réduites » les coupe même si le système n\'a rien demandé');
    assert.equal(resolveFx('reduce', true), false);
    assert.equal(resolveFx(null, false), true, 'Auto : le système ne demande rien');
    assert.equal(resolveFx(null, true), false, 'Auto : le système demande de réduire');
    assert.equal(resolveFx('nimporte-quoi', true), false, 'Une valeur inconnue vaut « auto »');
    assert.equal(resolveFx('nimporte-quoi', false), true);
});

test('easeOutCubic et countAt : de 0 à la cible, en décélérant, bornés', async () => {
    const { easeOutCubic, countAt } = await motionModule();

    assert.equal(easeOutCubic(0), 0);
    assert.equal(easeOutCubic(1), 1);
    assert.equal(easeOutCubic(-5), 0);
    assert.equal(easeOutCubic(7), 1);
    let previous = -1;
    for (let t = 0; t <= 1; t += 0.05) {
        const value = easeOutCubic(t);
        assert.ok(value >= previous, 'croissante');
        previous = value;
    }
    assert.ok(easeOutCubic(0.5) > 0.5, 'rapide au début, lente à la fin');
    assert.equal(countAt(1000, 0), 0);
    assert.equal(countAt(1000, 1), 1000);
    assert.equal(countAt(-250, 1), -250);
    assert.ok(countAt(1000, 0.5) > 500);
});

test('fxOn et storedChoice lisent l\'attribut de <html> et le stockage, sans jamais planter', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { fxOn, storedChoice } = await motionModule();

    assert.equal(fxOn(), true);
    browser.root.dataset.fx = 'off';
    assert.equal(fxOn(), false);
    delete browser.root.dataset.fx;
    assert.equal(fxOn(), false, 'Sans attribut (script d\'en-tête absent) : pas d\'animation');

    assert.equal(storedChoice(), null);
    browser.store.set('finora-motion', 'reduce');
    assert.equal(storedChoice(), 'reduce');
    browser.store.set('finora-motion', 'full');
    assert.equal(storedChoice(), 'full');
    browser.store.set('finora-motion', 'corrompu');
    assert.equal(storedChoice(), null, 'Valeur inconnue ignorée');
    browser.state.storageBroken = true;
    assert.equal(storedChoice(), null, 'Stockage indisponible (navigation privée)');
});

// ---------------------------------------------------------------------------------------------- réglage « Animations »

test('motion_controller : choisir Réduites coupe les animations tout de suite et se souvient du choix', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { default: Motion } = await loadController('controllers/motion_controller.js');
    const buttons = ['auto', 'full', 'reduce'].map((choice) => el('button', { 'data-motion-choice-param': choice }));
    const controller = mount(Motion, 'motion', el('div', {}, buttons));
    const pressed = () => buttons.map((b) => b.getAttribute('aria-pressed')).join(',');

    assert.equal(pressed(), 'true,false,false', 'Par défaut : Auto');
    assert.equal(browser.root.dataset.fx, 'on');

    controller.set({ params: { choice: 'reduce' } });
    assert.equal(browser.root.dataset.fx, 'off');
    assert.equal(browser.store.get('finora-motion'), 'reduce');
    assert.equal(pressed(), 'false,false,true');

    controller.set({ params: { choice: 'full' } });
    assert.equal(browser.root.dataset.fx, 'on');
    assert.equal(browser.store.get('finora-motion'), 'full');
    assert.equal(pressed(), 'false,true,false');
});

test('motion_controller : « Complètes » l\'emporte sur le système, « Auto » le suit', async () => {
    const browser = installBrowser({ fx: 'off', systemReducesMotion: true });
    const { default: Motion } = await loadController('controllers/motion_controller.js');
    const controller = mount(Motion, 'motion', el('div', {}, [el('button', { 'data-motion-choice-param': 'auto' })]));

    controller.set({ params: { choice: 'full' } });
    assert.equal(browser.root.dataset.fx, 'on', 'Le système demande de réduire mais l\'utilisateur a forcé');

    controller.set({ params: { choice: 'auto' } });
    assert.equal(browser.root.dataset.fx, 'off', 'Auto : le système demande de réduire');
    assert.equal(browser.store.has('finora-motion'), false, 'Auto efface le choix enregistré');

    // Le système change d'avis en cours de session : l'application suit tant qu'on est en Auto.
    browser.setSystemReducesMotion(false);
    assert.equal(browser.root.dataset.fx, 'on');
});

test('motion_controller : une valeur inconnue vaut Auto, un stockage bloqué n\'empêche pas le réglage', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { default: Motion } = await loadController('controllers/motion_controller.js');
    const controller = mount(Motion, 'motion', el('div'));

    controller.set({ params: { choice: 'violet' } });
    assert.equal(browser.store.has('finora-motion'), false);

    browser.state.storageBroken = true;
    assert.doesNotThrow(() => controller.set({ params: { choice: 'reduce' } }));
    assert.equal(browser.root.dataset.fx, 'off', 'Le réglage vaut pour la page même si on ne peut pas le mémoriser');
});

// ---------------------------------------------------------------------------------------------- thème

test('theme_controller : fondu entre thèmes (classe temporaire), choix mémorisé, retour à auto', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { mock } = await import('node:test');
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const { default: Theme } = await loadController('controllers/theme_controller.js');
        const controller = mount(Theme, 'theme', el('div'));

        controller.set({ params: { theme: 'dark' } });
        assert.equal(browser.root.dataset.theme, 'dark');
        assert.equal(browser.store.get('finora-theme'), 'dark');
        assert.ok(browser.root.classList.contains('theme-switching'), 'Fondu en cours');
        mock.timers.tick(449);
        assert.ok(browser.root.classList.contains('theme-switching'));
        mock.timers.tick(2);
        assert.equal(browser.root.classList.contains('theme-switching'), false, 'Fondu terminé : plus de transition globale');

        controller.set({ params: { theme: 'auto' } });
        assert.equal(browser.root.dataset.theme, undefined);
        assert.equal(browser.store.has('finora-theme'), false);

        // Depuis « auto » sur un appareil clair, la bascule va vers le sombre, puis revient au clair.
        controller.toggle();
        assert.equal(browser.root.dataset.theme, 'dark');
        controller.toggle();
        assert.equal(browser.root.dataset.theme, 'light');
    } finally {
        mock.timers.reset();
    }
});
