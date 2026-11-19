#!/usr/bin/env node
/*
 * Vérification dans un vrai navigateur (Chrome, Edge ou Chromium en mode headless), sur le serveur de développement :
 *
 *     node tools/browser/check.mjs [adresse]          # par défaut http://127.0.0.1:8000
 *
 * Prérequis : serveur lancé (symfony serve -d) et jeu de données de test chargé
 *     symfony console doctrine:fixtures:load --group=test --append
 *
 * Ce que le script contrôle, avec l'utilisateur de test complet :
 *   1. chaque page s'affiche sans erreur JavaScript, en clair et en sombre (captures dans var/screenshots) ;
 *   2. aucun défilement horizontal en 390, 360 et 320 px de large ;
 *   3. les interactions : carte inclinable, info-bulle de la courbe, donut, carte d'objectif retournée, bouton
 *      d'ajout rapide, compteurs animés, réglage « Animations » et respect de « réduire les animations » du système.
 * Code de sortie : 0 si tout est bon, 1 sinon.
 */
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { launch, sleep, testCredentials } from './cdp.mjs';

const base = (process.argv[2] ?? 'http://127.0.0.1:8000').replace(/\/$/, '');
const out = resolve(import.meta.dirname, '..', '..', 'var', 'screenshots');
mkdirSync(out, { recursive: true });

const PAGES = ['/', '/account', '/transaction', '/recurring', '/budget', '/goal', '/stats', '/calendar', '/settings', '/profile', '/category',
    '/account/new', '/transaction/new', '/budget/new', '/goal/new', '/recurring/new'];

let failures = 0;
const check = (ok, label, detail = '') => {
    if (!ok) { failures++; }
    console.log(`${ok ? '  ✔' : '  ✖'} ${label}${detail ? ` — ${detail}` : ''}`);
};

const { email, password } = testCredentials();
const browser = await launch();

try {
    // ------------------------------------------------------------------ 1. pages, clair et sombre
    console.log('\n1. Pages (clair et sombre)');
    await browser.viewport({ width: 1400, height: 900 });
    await browser.login(base, email, password);
    for (const dark of [false, true]) {
        await browser.viewport({ width: 1400, height: 900, dark });
        let broken = [];
        for (const path of PAGES) {
            browser.clearErrors();
            await browser.goto(`${base}${path}`, 700);
            const text = await browser.eval('document.body.innerText');
            if (browser.pageErrors.length || /Exception|Warning:|Error 500/i.test(text)) {
                broken.push(`${path} (${browser.pageErrors[0] ?? 'message d\'erreur dans la page'})`);
            }
            if (path === '/' || path === '/goal' || path === '/settings') {
                await browser.screenshot(join(out, `${path === '/' ? 'home' : path.slice(1)}-${dark ? 'dark' : 'light'}.png`));
            }
        }
        check(broken.length === 0, `${PAGES.length} pages sans erreur — thème ${dark ? 'sombre' : 'clair'}`, broken.join(' ; '));
    }

    // ------------------------------------------------------------------ 2. débordements en mobile
    console.log('\n2. Aucun défilement horizontal en mobile');
    for (const width of [390, 360, 320]) {
        await browser.viewport({ width, height: 844, mobile: true, reduceMotion: true });
        const overflowing = [];
        for (const path of PAGES) {
            await browser.goto(`${base}${path}`, 400);
            const over = await browser.eval(`(() => {
                const vw = document.documentElement.clientWidth;
                const wide = [...document.querySelectorAll('main *')].filter((e) => !e.closest('.table-wrap') && e.getBoundingClientRect().right > vw + 1);
                return document.documentElement.scrollWidth - vw > 0 || wide.length > 0;
            })()`);
            if (over) { overflowing.push(path); }
        }
        check(overflowing.length === 0, `${width} px`, overflowing.join(', '));
    }

    // ------------------------------------------------------------------ 3. interactions
    console.log('\n3. Interactions (bureau, animations actives)');
    await browser.viewport({ width: 1400, height: 900, reduceMotion: false });
    await browser.eval(`localStorage.removeItem('finora-motion')`);

    await browser.goto(`${base}/`, 3000);
    check(await browser.eval(`document.documentElement.dataset.fx`) === 'on', 'animations actives par défaut');

    const kpi = await browser.rect('.kpi:nth-child(2)');
    await browser.move(kpi.x + kpi.w * 0.85, kpi.y + kpi.h * 0.2);
    await sleep(400);
    check(await browser.eval(`!!document.querySelector('.kpi:nth-child(2).tilt.is-tilting') && document.querySelector('.kpi:nth-child(2)').style.getPropertyValue('--ry') !== ''`), 'une carte s\'incline en 3D sous le pointeur');
    await browser.move(5, 5);

    const chart = await browser.rect('.chart svg');
    await browser.move(chart.x + chart.w * 0.62, chart.y + chart.h * 0.45);
    await sleep(300);
    const tip = await browser.eval(`(() => { const t = document.querySelector('.chart-tip'); return t.hidden ? '' : t.innerText; })()`);
    check(/Revenus.*Dépenses/s.test(tip), 'l\'info-bulle de la courbe affiche revenus et dépenses', tip.replace(/\n/g, ' / '));
    await browser.move(5, 5);

    const legend = await browser.rect('.donut-legend li:nth-child(2)');
    await browser.move(legend.cx, legend.cy);
    await sleep(300);
    const center = await browser.eval(`document.querySelector('.donut-center').innerText.replace(/\\n/g, ' / ')`);
    const legendName = await browser.eval(`document.querySelector('.donut-legend li:nth-child(2)').dataset.label`);
    check(center.includes(legendName), 'le donut affiche la catégorie survolée au centre', center);
    await browser.move(5, 5);
    await sleep(300);
    check(await browser.eval(`document.querySelector('.donut-center small').innerText`) === 'Dépenses', 'le centre du donut se rétablit à la sortie');

    await browser.goto(`${base}/`, 120);
    const duringCount = await browser.eval(`document.querySelector('.kpi:nth-child(2) strong').textContent`);
    const targetCount = await browser.eval(`document.querySelector('.kpi:nth-child(2) strong').dataset.countupValueValue`);
    await sleep(2200);
    const finalCount = await browser.eval(`document.querySelector('.kpi:nth-child(2) strong').textContent`);
    const toNumber = (text) => Number(text.replace(/[^\d,-]/g, '').replace(',', '.'));
    check(toNumber(finalCount) === Number(targetCount) && toNumber(duringCount) <= Number(targetCount), 'les montants montent jusqu\'à leur valeur exacte', `${duringCount} → ${finalCount}`);

    await browser.goto(`${base}/goal`, 2500);
    const flipButton = await browser.rect('.goal-flip:nth-child(1) .goal-flip-btn');
    await browser.click(flipButton.cx, flipButton.cy);
    await sleep(1100);
    check(await browser.eval(`document.querySelector('.goal-flip').classList.contains('is-flipped') && document.querySelector('.goal-front').inert && !document.querySelector('.goal-back').inert`), 'une carte d\'objectif se retourne (face avant inerte, dos accessible)');
    await browser.screenshot(join(out, 'goal-flipped.png'));
    await browser.key('Escape');
    await sleep(900);
    check(await browser.eval(`!document.querySelector('.goal-flip').classList.contains('is-flipped')`), 'Échap ramène à la face avant');

    await browser.goto(`${base}/budget`, 1800);
    const fab = await browser.rect('.fab-main');
    await browser.click(fab.cx, fab.cy);
    await sleep(800);
    check(await browser.eval(`document.querySelector('.fab').classList.contains('is-open') && document.querySelector('.fab-main').getAttribute('aria-expanded') === 'true' && document.querySelectorAll('.fab-menu a').length === 5`), 'le bouton d\'ajout rapide ouvre ses 5 raccourcis');
    await browser.screenshot(join(out, 'fab-open.png'));
    await browser.key('Escape');
    await sleep(500);
    check(await browser.eval(`!document.querySelector('.fab').classList.contains('is-open')`), 'Échap ferme le menu d\'ajout');

    await browser.goto(`${base}/`, 1500);
    await browser.eval(`window.__marker = 1`);
    const link = await browser.rect('.sidebar a[href="/account"]');
    await browser.click(link.cx, link.cy);
    await sleep(1400);
    check(await browser.eval(`location.pathname === '/account' && window.__marker === 1`), 'la navigation se fait sans rechargement complet (Turbo)');

    // ------------------------------------------------------------------ 4. animations réduites
    console.log('\n4. Animations réduites');
    await browser.goto(`${base}/settings`, 1000);
    await browser.eval(`document.querySelector('.motion-switch button:nth-child(3)').click()`);
    await sleep(200);
    check(await browser.eval(`document.documentElement.dataset.fx === 'off' && localStorage.getItem('finora-motion') === 'reduce'`), 'le choix « Réduites » coupe les animations et se mémorise');
    await browser.goto(`${base}/`, 800);
    check(await browser.eval(`document.documentElement.dataset.fx === 'off' && document.querySelectorAll('.reveal').length === 0 && !document.querySelector('.tilt')`), 'rien n\'est masqué ni animé sur la page suivante');
    await browser.eval(`localStorage.removeItem('finora-motion')`);

    await browser.viewport({ width: 1400, height: 900, reduceMotion: true });
    await browser.goto(`${base}/`, 800);
    check(await browser.eval(`document.documentElement.dataset.fx`) === 'off', 'le réglage « réduire les animations » du système est respecté');
    await browser.eval(`localStorage.setItem('finora-motion', 'full')`);
    await browser.goto(`${base}/`, 800);
    check(await browser.eval(`document.documentElement.dataset.fx`) === 'on', '« Complètes » prend le dessus sur le système');
    await browser.eval(`localStorage.removeItem('finora-motion')`);
} finally {
    browser.close();
}

console.log(failures === 0 ? `\nTout est bon. Captures : ${out}` : `\n${failures} vérification(s) en échec. Captures : ${out}`);
process.exit(failures === 0 ? 0 : 1);
