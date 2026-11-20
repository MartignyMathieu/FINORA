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

test('privacy_controller : masque et affiche le solde, choix mémorisé et appliqué à tous les boutons', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { default: Privacy } = await loadController('controllers/privacy_controller.js');
    const first = mount(Privacy, 'privacy', el('button'));
    const second = mount(Privacy, 'privacy', el('button'));

    assert.equal(first.element.getAttribute('aria-pressed'), 'false');
    assert.equal(first.element.getAttribute('aria-label'), 'Masquer le solde total');

    first.toggle();
    assert.equal(browser.root.dataset.hideBalance, '1');
    assert.equal(browser.store.get('finora-hide-balance'), '1');
    assert.equal(first.element.getAttribute('aria-pressed'), 'true');
    assert.equal(first.element.getAttribute('aria-label'), 'Afficher le solde total');
    // L'autre bouton (carte mobile / carte bureau) suit, par l'événement partagé.
    assert.equal(second.element.getAttribute('aria-pressed'), 'true');

    second.toggle();
    assert.equal(browser.root.dataset.hideBalance, undefined);
    assert.equal(browser.store.has('finora-hide-balance'), false);
    assert.equal(first.element.getAttribute('aria-pressed'), 'false');
});

test('alert_dismiss_controller : se ferme, mémorise l\'empreinte et ne revient que si les alertes changent', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { default: AlertDismiss } = await loadController('controllers/alert_dismiss_controller.js');

    // Première visite : visible.
    const first = mount(AlertDismiss, 'alert-dismiss', el('section', { 'data-alert-dismiss-signature-value': '1:warn' }));
    assert.ok(!first.element.hidden, 'Première visite : visible');

    first.dismiss();
    assert.equal(first.element.hidden, true);
    assert.equal(browser.store.get('finora-dismissed-alerts'), '1:warn');

    // Même empreinte (page suivante) : reste cachée.
    const same = mount(AlertDismiss, 'alert-dismiss', el('section', { 'data-alert-dismiss-signature-value': '1:warn' }));
    assert.equal(same.element.hidden, true);

    // Un budget change de niveau : la bannière revient.
    const changed = mount(AlertDismiss, 'alert-dismiss', el('section', { 'data-alert-dismiss-signature-value': '1:over' }));
    assert.ok(!changed.element.hidden, 'Empreinte différente : la bannière revient');
});

test('bulk_controller : la barre apparaît avec la sélection, le compteur et « tout sélectionner » suivent, les boutons dépendent de leur liste', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { default: Bulk } = await loadController('controllers/bulk_controller.js');

    const rows = [1, 2, 3].map(() => el('li'));
    const items = rows.map((row) => {
        const box = el('input', { 'data-bulk-target': 'item' });
        row.append(box);

        return box;
    });
    const all = el('input', { 'data-bulk-target': 'all' });
    const count = el('strong', { 'data-bulk-target': 'count' });
    const bar = el('form', { 'data-bulk-target': 'bar' });
    const category = el('select', { 'data-bulk-target': 'choice', 'data-bulk-for': 'category' });
    category.value = '';
    const moveCategory = el('button', { 'data-bulk-target': 'apply', value: 'category' });
    moveCategory.value = 'category';
    const remove = el('button', { 'data-bulk-target': 'apply' });
    remove.value = 'delete';
    const root = el('div', {}, [...rows, all, count, bar, category, moveCategory, remove]);
    const controller = mount(Bulk, 'bulk', root);

    // Rien de coché : barre cachée, boutons dépendants désactivés, suppression laissée active (elle ne dépend d'aucune liste).
    assert.equal(bar.hidden, true);
    assert.equal(count.textContent, '0 sélectionnée');
    assert.equal(browser.document.body.classList.contains('has-selection'), false);
    assert.equal(moveCategory.disabled, true);
    assert.equal(remove.disabled, false);

    // Une ligne cochée.
    items[0].checked = true;
    controller.update();
    assert.equal(bar.hidden, false);
    assert.equal(count.textContent, '1 sélectionnée');
    assert.ok(rows[0].classList.contains('is-selected'));
    assert.equal(rows[1].classList.contains('is-selected'), false);
    assert.equal(all.indeterminate, true, 'Sélection partielle');
    assert.equal(all.checked, false);
    assert.ok(browser.document.body.classList.contains('has-selection'), 'Le bouton d\'ajout rapide est masqué par le CSS');

    // Choisir une catégorie active « Déplacer ».
    category.value = '12';
    controller.refreshButtons();
    assert.equal(moveCategory.disabled, false);

    // Tout sélectionner, puis tout désélectionner.
    all.checked = true;
    controller.toggleAll();
    assert.ok(items.every((box) => box.checked));
    assert.equal(count.textContent, '3 sélectionnées');
    assert.equal(all.indeterminate, false);

    controller.clear();
    assert.ok(items.every((box) => !box.checked));
    assert.equal(bar.hidden, true);
    assert.equal(count.textContent, '0 sélectionnée');

    // Quitter la page retire la classe globale.
    items[1].checked = true;
    controller.update();
    controller.disconnect();
    assert.equal(browser.document.body.classList.contains('has-selection'), false);
});
