#!/usr/bin/env node
/*
 * Génère les icônes de l'application (public/icons/*.png) à partir du symbole « F » FINORA, avec Chrome / Edge en headless :
 *
 *     node tools/icons/generate.mjs
 *
 * Le symbole reste dans la zone centrale (60 %) : les systèmes qui découpent l'icône en cercle ou en carré arrondi
 * (icônes « maskable » d'Android) n'en coupent rien.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { launch } from '../browser/cdp.mjs';

const out = resolve(import.meta.dirname, '..', '..', 'public', 'icons');
mkdirSync(out, { recursive: true });

const mark = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="120 40 520 500" width="58%" height="58%">
  <defs>
    <linearGradient id="a" x1="0.1" y1="1" x2="0.95" y2="0"><stop offset="0" stop-color="#1f6fef"/><stop offset="1" stop-color="#5cd9ff"/></linearGradient>
    <linearGradient id="b" x1="0.1" y1="1" x2="0.9" y2="0.1"><stop offset="0" stop-color="#0a45ff"/><stop offset="1" stop-color="#19aaff"/></linearGradient>
  </defs>
  <path fill="url(#a)" d="M190 308C198 150 300 62 470 60L606 58C596 135 560 196 478 199L330 201C262 206 218 256 190 308Z"/>
  <path fill="url(#b)" d="M148 514C176 400 206 252 342 246L518 243C536 244 522 300 490 336C466 360 440 366 396 366L342 368C316 372 308 400 300 432C284 486 232 520 148 514Z"/>
</svg>`;
const page = `<!doctype html><meta charset="utf-8"><style>html,body{margin:0;height:100%}body{display:grid;place-items:center;
background:radial-gradient(circle at 30% 20%,#14305c 0%,#0b1f3c 55%,#081326 100%)}</style>${mark}`;

const sizes = { 'icon-192.png': 192, 'icon-512.png': 512, 'icon-maskable-512.png': 512, 'apple-touch-icon.png': 180 };
const browser = await launch();
try {
    for (const [file, size] of Object.entries(sizes)) {
        await browser.send('Emulation.setDeviceMetricsOverride', { width: size, height: size, deviceScaleFactor: 1, mobile: false });
        await browser.goto(`data:text/html;charset=utf-8,${encodeURIComponent(page)}`, 150);
        const { data } = await browser.send('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: size, height: size, scale: 1 } });
        writeFileSync(join(out, file), Buffer.from(data, 'base64'));
        console.log(`✔ ${file} (${size}×${size})`);
    }
} finally {
    browser.close();
}
