import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { installBrowser, loadController, mount, el } from './support.mjs';

const withTimers = async (fn) => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        await fn();
    } finally {
        mock.timers.reset();
    }
};

// ---------------------------------------------------------------------------------------------- tilt (carte 3D)

test('tiltAngles : le bord proche du pointeur s\'éloigne, le centre reste à plat, bornes respectées', async () => {
    installBrowser();
    const { tiltAngles } = await loadController('controllers/tilt_controller.js');

    assert.deepEqual(tiltAngles(0.5, 0.5, 8), { rx: -0, ry: 0 }, 'Au centre : à plat');
    assert.deepEqual(tiltAngles(1, 0.5, 8), { rx: -0, ry: 8 }, 'Pointeur à droite : bord droit qui s\'éloigne');
    assert.deepEqual(tiltAngles(0, 0.5, 8), { rx: -0, ry: -8 });
    assert.deepEqual(tiltAngles(0.5, 0, 8), { rx: 8, ry: 0 }, 'Pointeur en haut : bord haut qui s\'éloigne');
    assert.deepEqual(tiltAngles(0.5, 1, 8), { rx: -8, ry: 0 });
    assert.deepEqual(tiltAngles(9, -9, 10), { rx: 10, ry: 10 }, 'Hors de la carte : borné à l\'angle maximal');
    assert.ok(Math.abs(tiltAngles(0.75, 0.25, 8).ry) < 8, 'Angle proportionnel à l\'écart au centre');
});

test('tilt : rien si les animations sont coupées ou si l\'appareil n\'a pas de survol (tactile)', async () => {
    for (const options of [{ fx: 'off' }, { fx: 'on', hover: false }]) {
        installBrowser(options);
        const { default: Tilt } = await loadController('controllers/tilt_controller.js');
        const card = el('div');
        mount(Tilt, 'tilt', card);

        assert.equal(card.classList.contains('tilt'), false, JSON.stringify(options));
        assert.equal(card.children.length, 0, 'Pas de reflet ajouté');
        assert.equal(card.listeners.size, 0, 'Aucun écouteur posé');
    }
});

test('tilt : suit le pointeur (angles et reflet), se remet à plat en sortant et se nettoie à la déconnexion', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { default: Tilt } = await loadController('controllers/tilt_controller.js');
    const card = el('div', { 'data-tilt-max-value': '10' });
    card.rect = { left: 100, top: 100, width: 200, height: 100, right: 300, bottom: 200 };
    const controller = mount(Tilt, 'tilt', card);

    assert.ok(card.classList.contains('tilt'));
    const glare = card.children[0];
    assert.equal(glare.className, 'tilt-glare');
    assert.equal(glare.getAttribute('aria-hidden'), 'true', 'Le reflet est décoratif');

    // Pointeur au quart gauche, en haut : x = 0.25, y = 0.
    card.dispatchEvent({ type: 'pointermove', clientX: 150, clientY: 100 });
    browser.frame(16);
    assert.ok(card.classList.contains('is-tilting'));
    assert.equal(card.style.getPropertyValue('--rx'), '10.00deg');
    assert.equal(card.style.getPropertyValue('--ry'), '-5.00deg');
    assert.equal(card.style.getPropertyValue('--gx'), '25.0%');
    assert.equal(card.style.getPropertyValue('--gy'), '0.0%');

    card.dispatchEvent({ type: 'pointerleave' });
    assert.equal(card.classList.contains('is-tilting'), false);
    ['--rx', '--ry', '--gx', '--gy'].forEach((v) => assert.equal(card.style.getPropertyValue(v), '', v + ' remis à zéro'));

    controller.disconnect();
    assert.equal(card.classList.contains('tilt'), false);
    assert.equal(glare.removed, true, 'Le reflet est retiré');
    assert.equal(card.listeners.get('pointermove').size, 0);
});

test('tilt : une carte sans dimensions (masquée) ne produit pas d\'angle absurde', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { default: Tilt } = await loadController('controllers/tilt_controller.js');
    const card = el('div');
    mount(Tilt, 'tilt', card);

    card.dispatchEvent({ type: 'pointermove', clientX: 50, clientY: 50 });
    browser.frame(16);

    assert.equal(card.style.getPropertyValue('--rx'), '');
});

// ---------------------------------------------------------------------------------------------- countup

test('countup : les montants montent puis le texte du serveur est remis à l\'identique', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { default: Countup } = await loadController('controllers/countup_controller.js');
    const amount = el('strong', { 'data-countup-value-value': '1250.5', 'data-countup-currency-value': 'EUR' });
    amount.textContent = '1 250,50 €';
    mount(Countup, 'countup', amount);

    assert.equal(amount.textContent, '1 250,50 €', 'Avant d\'être visible : texte du serveur intact');
    browser.intersect(amount);

    browser.frame(0);
    browser.frame(300);
    const middle = amount.textContent;
    assert.notEqual(middle, '1 250,50 €', 'Valeur intermédiaire pendant l\'animation');
    assert.match(middle, /€/, 'Avec le symbole de la devise');
    const numeric = Number(middle.replace(/[^\d,]/g, '').replace(',', '.'));
    assert.ok(numeric > 0 && numeric < 1250.5, `valeur intermédiaire entre 0 et la cible (${numeric})`);

    browser.frame(700);
    assert.equal(amount.textContent, '1 250,50 €', 'Texte exact du serveur à la fin');
});

test('countup : sans animations, ou valeur invalide, le texte n\'est jamais touché', async () => {
    for (const [fx, value] of [['off', '100'], ['on', 'abc']]) {
        const browser = installBrowser({ fx });
        const { default: Countup } = await loadController('controllers/countup_controller.js');
        const amount = el('strong', { 'data-countup-value-value': value });
        amount.textContent = '100,00';
        mount(Countup, 'countup', amount);

        assert.equal(browser.state.observers.length, 0, `${fx}/${value} : aucun observateur`);
        assert.equal(amount.textContent, '100,00');
    }
});

test('countup : quitter la page en cours d\'animation ne laisse aucune valeur intermédiaire', async () => {
    const browser = installBrowser({ fx: 'on' });
    const { default: Countup } = await loadController('controllers/countup_controller.js');
    const amount = el('strong', { 'data-countup-value-value': '500', 'data-countup-decimals-value': '0', 'data-countup-suffix-value': ' %' });
    amount.textContent = '500 %';
    const controller = mount(Countup, 'countup', amount);
    browser.intersect(amount);
    browser.frame(0);
    browser.frame(200);
    assert.notEqual(amount.textContent, '500 %');

    controller.disconnect();

    assert.equal(amount.textContent, '500 %');
});

// ---------------------------------------------------------------------------------------------- reveal

test('reveal : rien n\'est masqué si les animations sont coupées', async () => {
    const browser = installBrowser({ fx: 'off' });
    const { default: Reveal } = await loadController('controllers/reveal_controller.js');
    const main = el('main', {}, [el('div', { class: 'card' })]);
    mount(Reveal, 'reveal', main);

    assert.equal(main.classList.contains('reveal-ready'), false);
    assert.equal(main.children[0].classList.contains('reveal'), false);
    assert.equal(browser.state.observers.length, 0);
});

test('reveal : les cartes et les lignes apparaissent à tour de rôle puis les classes sont retirées', async () => {
    const browser = installBrowser({ fx: 'on' });
    await withTimers(async () => {
        const { default: Reveal } = await loadController('controllers/reveal_controller.js');
        const rows = Array.from({ length: 9 }, () => el('li'));
        const list = el('ul', { class: 'list' }, rows);
        const card = el('section', { class: 'card' });
        const inner = el('div', { class: 'card' }); // une carte dans une carte : animée avec son parent, pas séparément
        card.append(inner);
        const main = el('main', {}, [list, card]);
        mount(Reveal, 'reveal', main);

        assert.ok(main.classList.contains('reveal-ready'));
        assert.ok(card.classList.contains('reveal'));
        assert.equal(inner.classList.contains('reveal'), false, 'Pas d\'animation imbriquée');
        assert.ok(rows.every((r) => r.classList.contains('reveal')), 'Les lignes de la liste sont animées');
        assert.equal(rows[0].classList.contains('is-visible'), false, 'Masquées tant qu\'elles ne sont pas à l\'écran');

        browser.intersect(...rows, card);
        assert.ok(rows.every((r) => r.classList.contains('is-visible')));
        const delays = rows.map((r) => r.style.getPropertyValue('--reveal-delay'));
        assert.equal(delays[0], '0ms');
        assert.equal(delays[1], '70ms');
        assert.equal(delays[8], '420ms', 'Décalage plafonné à 6 pas pour ne pas faire attendre');

        mock.timers.tick(1199);
        assert.ok(rows[0].classList.contains('reveal'));
        mock.timers.tick(2);
        assert.ok(rows.every((r) => !r.classList.contains('reveal') && !r.classList.contains('is-visible')), 'Nettoyé : le survol retrouve ses transitions');
        assert.equal(rows[0].style.getPropertyValue('--reveal-delay'), '');
    });
});

test('reveal : se remet à zéro à la déconnexion et avant la mise en cache de Turbo', async () => {
    const browser = installBrowser({ fx: 'on' });
    await withTimers(async () => {
        const { default: Reveal } = await loadController('controllers/reveal_controller.js');
        const card = el('div', { class: 'card' });
        const main = el('main', {}, [card]);
        const controller = mount(Reveal, 'reveal', main);
        assert.ok(card.classList.contains('reveal'));

        window.dispatchEvent(new Event('turbo:before-cache'));
        // L'écouteur est posé sur document dans la vraie page ; ici on l'appelle comme le ferait Turbo.
        document.dispatchEvent(new Event('turbo:before-cache'));
        assert.equal(card.classList.contains('reveal'), false, 'Instantané Turbo : jamais de contenu masqué en cache');
        assert.equal(main.classList.contains('reveal-ready'), false);

        controller.disconnect();
        assert.equal(browser.state.observers[0].disconnected, true);
    });
});

// ---------------------------------------------------------------------------------------------- flip (carte à deux faces)

const flipCard = () => {
    const back = el('div', { 'data-flip-target': 'back' }, [el('a', { href: '/goal/1' })]);
    const front = el('div', { 'data-flip-target': 'front' });
    const button = el('button', { 'data-flip-target': 'button' });
    front.append(button);

    return { card: el('article', {}, [front, back]), front, back, button };
};

test('flip : à l\'état initial seule la face avant est accessible', async () => {
    installBrowser();
    const { default: Flip } = await loadController('controllers/flip_controller.js');
    const { card, front, back, button } = flipCard();
    mount(Flip, 'flip', card);

    assert.equal(card.classList.contains('is-flipped'), false);
    assert.equal(front.inert, false);
    assert.equal(back.inert, true, 'Le dos n\'est pas atteignable au clavier');
    assert.equal(front.getAttribute('aria-hidden'), 'false');
    assert.equal(back.getAttribute('aria-hidden'), 'true', 'Ni lu par les lecteurs d\'écran');
    assert.equal(button.getAttribute('aria-expanded'), 'false');
});

test('flip : retourner rend le dos accessible et y place le focus ; revenir rend le focus au bouton', async () => {
    const browser = installBrowser();
    const { default: Flip } = await loadController('controllers/flip_controller.js');
    const { card, front, back, button } = flipCard();
    const controller = mount(Flip, 'flip', card);

    controller.toggle();
    assert.ok(card.classList.contains('is-flipped'));
    assert.equal(front.inert, true);
    assert.equal(back.inert, false);
    assert.equal(button.getAttribute('aria-expanded'), 'true');
    assert.equal(browser.document.activeElement, back.children[0], 'Focus sur le premier lien du dos');

    controller.toggle();
    assert.equal(card.classList.contains('is-flipped'), false);
    assert.equal(back.inert, true);
    assert.equal(browser.document.activeElement, button, 'Focus rendu au bouton qui a ouvert');
});

test('flip : Échap ramène à la face avant, seulement si la carte est retournée', async () => {
    const browser = installBrowser();
    const { default: Flip } = await loadController('controllers/flip_controller.js');
    const { card, button } = flipCard();
    const controller = mount(Flip, 'flip', card);

    card.dispatchEvent({ type: 'keydown', key: 'Escape' });
    assert.equal(browser.document.activeElement, null, 'Échap sur une carte non retournée : rien');

    controller.toggle();
    card.dispatchEvent({ type: 'keydown', key: 'a' });
    assert.ok(card.classList.contains('is-flipped'), 'Une autre touche ne fait rien');
    card.dispatchEvent({ type: 'keydown', key: 'Escape' });
    assert.equal(card.classList.contains('is-flipped'), false);
    assert.equal(browser.document.activeElement, button);

    controller.disconnect();
    assert.equal(card.listeners.get('keydown').size, 0);
});

// ---------------------------------------------------------------------------------------------- fab (ajout rapide)

const fab = () => {
    const link = el('a', { href: '/account/new' });
    const menu = el('ul', { 'data-fab-target': 'menu' }, [el('li', {}, [link])]);
    const button = el('a', { 'data-fab-target': 'button', href: '/transaction/new' });
    const root = el('div', {}, [menu, button]);
    document.body.append(root);

    return { root, menu, button, link };
};

test('fab : fermé au départ, le lien de secours devient un bouton', async () => {
    installBrowser();
    const { default: Fab } = await loadController('controllers/fab_controller.js');
    const { root, menu, button } = fab();
    mount(Fab, 'fab', root);

    assert.equal(button.getAttribute('role'), 'button');
    assert.equal(button.getAttribute('aria-expanded'), 'false');
    assert.equal(button.getAttribute('aria-label'), 'Ajouter');
    assert.equal(menu.inert, true, 'Raccourcis hors de portée du clavier tant que le menu est fermé');
    assert.equal(root.classList.contains('is-open'), false);
});

test('fab : un clic ouvre le menu sans suivre le lien, un second le ferme', async () => {
    const browser = installBrowser();
    const { default: Fab } = await loadController('controllers/fab_controller.js');
    const { root, menu, button, link } = fab();
    const controller = mount(Fab, 'fab', root);

    const click = new Event('click');
    controller.toggle(click);
    assert.equal(click.defaultPrevented, true, 'Le lien de secours (sans JavaScript) n\'est pas suivi');
    assert.ok(root.classList.contains('is-open'));
    assert.equal(button.getAttribute('aria-expanded'), 'true');
    assert.equal(button.getAttribute('aria-label'), 'Fermer le menu d\'ajout');
    assert.equal(menu.inert, false);
    assert.equal(browser.document.activeElement, link, 'Focus sur le premier raccourci');

    controller.toggle(new Event('click'));
    assert.equal(root.classList.contains('is-open'), false);
    assert.equal(menu.inert, true);
    assert.equal(button.getAttribute('aria-label'), 'Ajouter');
});

test('fab : Échap et un clic ailleurs ferment, un clic dedans ne ferme pas', async () => {
    const browser = installBrowser();
    const { default: Fab } = await loadController('controllers/fab_controller.js');
    const { root, button, link } = fab();
    const controller = mount(Fab, 'fab', root);

    controller.toggle(new Event('click'));
    browser.document.dispatchEvent({ type: 'click', target: link });
    assert.ok(root.classList.contains('is-open'), 'Clic à l\'intérieur : reste ouvert');

    browser.document.dispatchEvent({ type: 'click', target: el('div') });
    assert.equal(root.classList.contains('is-open'), false, 'Clic ailleurs : fermé');

    controller.toggle(new Event('click'));
    browser.document.dispatchEvent({ type: 'keydown', key: 'Escape' });
    assert.equal(root.classList.contains('is-open'), false);
    assert.equal(browser.document.activeElement, button, 'Échap rend le focus au bouton');

    // Échap alors que le menu est fermé : aucun effet de bord sur le focus.
    browser.document.activeElement = null;
    browser.document.dispatchEvent({ type: 'keydown', key: 'Escape' });
    assert.equal(browser.document.activeElement, null);

    controller.disconnect();
    assert.equal(browser.document.listeners.get('click').size, 0);
    assert.equal(browser.document.listeners.get('keydown').size, 0);
});

// ---------------------------------------------------------------------------------------------- flash

test('flash : une confirmation disparaît seule, une erreur reste jusqu\'à ce qu\'on la ferme', async () => {
    installBrowser({ fx: 'on' });
    await withTimers(async () => {
        const { default: Flash } = await loadController('controllers/flash_controller.js');
        const success = el('div', { class: 'flash flash-success' });
        const error = el('div', { class: 'flash flash-error' });
        mount(Flash, 'flash', success);
        mount(Flash, 'flash', error);

        mock.timers.tick(5999);
        assert.equal(success.classList.contains('is-leaving'), false);
        mock.timers.tick(2);
        assert.ok(success.classList.contains('is-leaving'), 'Sortie animée après 6 s');
        mock.timers.tick(400);
        assert.equal(success.removed, true, 'Retiré de la page');

        mock.timers.tick(60000);
        assert.equal(error.removed, undefined, 'Une erreur n\'est jamais retirée automatiquement');
        assert.equal(error.classList.contains('is-leaving'), false);
    });
});

test('flash : la fermeture manuelle retire le message, tout de suite sans animations', async () => {
    for (const fx of ['on', 'off']) {
        installBrowser({ fx });
        await withTimers(async () => {
            const { default: Flash } = await loadController('controllers/flash_controller.js');
            const message = el('div', { class: 'flash flash-error' });
            const controller = mount(Flash, 'flash', message);

            controller.close();
            if (fx === 'off') {
                assert.equal(message.removed, true, 'Sans animations : retiré immédiatement');
            } else {
                assert.ok(message.classList.contains('is-leaving'));
                mock.timers.tick(401);
                assert.equal(message.removed, true);
            }
        });
    }
});

test('flash : le délai est réglable, et 0 désactive la disparition automatique', async () => {
    installBrowser({ fx: 'off' });
    await withTimers(async () => {
        const { default: Flash } = await loadController('controllers/flash_controller.js');
        const quick = el('div', { class: 'flash flash-success', 'data-flash-delay-value': '1000' });
        const manual = el('div', { class: 'flash flash-success', 'data-flash-delay-value': '0' });
        mount(Flash, 'flash', quick);
        mount(Flash, 'flash', manual);

        mock.timers.tick(1001);
        assert.equal(quick.removed, true);
        mock.timers.tick(100000);
        assert.equal(manual.removed, undefined);
    });
});

// ---------------------------------------------------------------------------------------------- donut

const donut = () => {
    const segments = [0, 1, 2].map((i) => el('circle', { 'data-donut-target': 'segment', 'data-index': i, 'data-label': ['Logement', 'Alimentation', 'Loisirs'][i], 'data-amount': ['500,00 €', '320,00 €', '80,00 €'][i] }));
    const items = [0, 1, 2].map((i) => el('li', { 'data-donut-target': 'item', 'data-index': i, 'data-label': ['Logement', 'Alimentation', 'Loisirs'][i], 'data-amount': ['500,00 €', '320,00 €', '80,00 €'][i] }));
    const value = el('strong', { 'data-donut-target': 'value' });
    value.textContent = '900,00 €';
    const label = el('small', { 'data-donut-target': 'label' });
    label.textContent = 'Dépenses';
    const root = el('div', {}, [...segments, ...items, value, label]);

    return { root, segments, items, value, label };
};

test('donut : survoler une ligne met la catégorie en avant et affiche son montant au centre', async () => {
    installBrowser();
    const { default: Donut } = await loadController('controllers/donut_controller.js');
    const { root, segments, items, value, label } = donut();
    const controller = mount(Donut, 'donut', root);

    controller.enter({ currentTarget: items[1] });

    assert.ok(root.classList.contains('has-active'), 'Les autres parts s\'estompent (CSS)');
    assert.deepEqual(segments.map((s) => s.classList.contains('is-active')), [false, true, false]);
    assert.deepEqual(items.map((s) => s.classList.contains('is-active')), [false, true, false], 'Légende et anneau restent synchronisés');
    assert.equal(value.textContent, '320,00 €');
    assert.equal(label.textContent, 'Alimentation');
});

test('donut : survoler une part de l\'anneau fait de même, et la sortie rétablit le centre', async () => {
    installBrowser();
    const { default: Donut } = await loadController('controllers/donut_controller.js');
    const { root, segments, items, value, label } = donut();
    const controller = mount(Donut, 'donut', root);

    controller.enter({ currentTarget: segments[2] });
    assert.deepEqual(items.map((s) => s.classList.contains('is-active')), [false, false, true]);
    assert.equal(value.textContent, '80,00 €');

    controller.enter({ currentTarget: segments[0] });
    assert.equal(label.textContent, 'Logement', 'Passer d\'une part à l\'autre met à jour sans passer par la sortie');

    controller.leave();
    assert.equal(root.classList.contains('has-active'), false);
    assert.ok([...segments, ...items].every((e) => !e.classList.contains('is-active')));
    assert.equal(value.textContent, '900,00 €', 'Total d\'origine rétabli');
    assert.equal(label.textContent, 'Dépenses');
});

// ---------------------------------------------------------------------------------------------- chart

const chart = () => {
    const hits = [0, 1].map((i) => el('rect', {
        'data-chart-target': 'hit', 'data-cx': i === 0 ? '100' : '400',
        'data-month': i === 0 ? 'Octobre 2026' : 'Novembre 2026', 'data-income': '2 500,00 €', 'data-expense': '1 250,00 €',
    }));
    const cursor = el('line', { 'data-chart-target': 'cursor' });
    const tip = el('div', { 'data-chart-target': 'tip' });
    tip.offsetWidth = 120;
    const svg = el('svg');
    svg.viewBox = { baseVal: { width: 560 } };
    const root = el('div', {}, [svg, ...hits, cursor, tip]);
    root.rect = { left: 0, top: 0, width: 560, height: 250, right: 560, bottom: 250 };

    return { root, hits, cursor, tip };
};

test('chart : survoler un mois affiche son info-bulle et son trait de repère', async () => {
    installBrowser();
    const { default: Chart } = await loadController('controllers/chart_controller.js');
    const { root, hits, cursor, tip } = chart();
    const controller = mount(Chart, 'chart', root);
    assert.equal(tip.hidden, true, 'Cachée au départ');

    controller.show({ currentTarget: hits[1] });

    assert.equal(tip.hidden, false);
    assert.deepEqual(tip.children.map((c) => c.textContent), ['Novembre 2026', 'Revenus : 2 500,00 €', 'Dépenses : 1 250,00 €']);
    assert.deepEqual(tip.children.map((c) => c.className), ['chart-tip-month', 'chart-tip-income', 'chart-tip-expense']);
    assert.equal(cursor.getAttribute('x1'), '400');
    assert.equal(cursor.getAttribute('x2'), '400');
    assert.ok(cursor.classList.contains('is-visible'));
    assert.deepEqual(hits.map((h) => h.classList.contains('is-active')), [false, true]);
    assert.equal(tip.style.left, '400px');

    controller.hide();
    assert.equal(tip.hidden, true);
    assert.equal(cursor.classList.contains('is-visible'), false);
    assert.ok(hits.every((h) => !h.classList.contains('is-active')));
});

test('chart : l\'info-bulle ne sort jamais du graphique et suit l\'échelle d\'affichage', async () => {
    installBrowser();
    const { default: Chart, clampTipLeft } = await loadController('controllers/chart_controller.js');

    assert.equal(clampTipLeft(10, 60, 560), 60, 'Collée à gauche');
    assert.equal(clampTipLeft(550, 60, 560), 500, 'Collée à droite');
    assert.equal(clampTipLeft(300, 60, 560), 300, 'Au milieu : centrée sur le mois');
    assert.equal(clampTipLeft(100, 400, 300), 400, 'Info-bulle plus large que le graphique : pas de valeur négative');

    // Graphique affiché à moitié de sa taille (écran étroit) : les positions sont mises à l'échelle.
    const { root, hits, tip } = chart();
    root.rect = { left: 0, top: 0, width: 280, height: 125, right: 280, bottom: 125 };
    const controller = mount(Chart, 'chart', root);
    controller.show({ currentTarget: hits[1] });
    assert.equal(tip.style.left, '200px', '400 unités SVG × 280/560');
});

test('chart : le texte de l\'info-bulle est du texte, jamais du HTML (pas d\'injection)', async () => {
    installBrowser();
    const { default: Chart } = await loadController('controllers/chart_controller.js');
    const { root, hits, tip } = chart();
    hits[0].setAttribute('data-month', '<img src=x onerror="alert(1)">');
    hits[0].setAttribute('data-income', '<script>alert(2)</script>');
    const controller = mount(Chart, 'chart', root);

    controller.show({ currentTarget: hits[0] });

    assert.equal(tip.children.length, 3, 'Trois lignes de texte, aucun élément injecté');
    assert.ok(tip.children.every((c) => c.tagName === 'SPAN' && c.children.length === 0));
    assert.equal(tip.children[0].textContent, '<img src=x onerror="alert(1)">');
    assert.equal(tip.children[1].textContent, 'Revenus : <script>alert(2)</script>');
});

test('chart : une zone inconnue est ignorée', async () => {
    installBrowser();
    const { default: Chart } = await loadController('controllers/chart_controller.js');
    const { root, tip } = chart();
    const controller = mount(Chart, 'chart', root);

    controller.show({ currentTarget: el('rect') });

    assert.equal(tip.hidden, true);
});
