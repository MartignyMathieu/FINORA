import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

/*
 * Tests du service worker (public/sw.js) : le vrai fichier est exécuté dans un contexte isolé, avec un faux Cache Storage et un faux réseau.
 * Aucune dépendance, aucun navigateur.
 */
const ORIGIN = 'https://finora.test';
const SOURCE = readFileSync(resolve(import.meta.dirname, '..', '..', 'public', 'sw.js'), 'utf8');

/** Requête de test (un objet simple suffit : le worker lit url, method, mode et headers). */
function req(path, { method = 'GET', mode = 'navigate', accept = 'text/html', headers = {} } = {}) {
    return { url: new URL(path, ORIGIN).href, method, mode, headers: new Headers({ Accept: accept, ...headers }) };
}

const html = (body = '<html><head><title>Page</title></head><body>Contenu</body></html>', init = {}) =>
    new Response(body, { status: 200, headers: { 'Content-Type': 'text/html; charset=UTF-8', 'Cache-Control': 'no-cache, private', ...(init.headers ?? {}) }, ...init, });

function createWorker(network) {
    const handlers = {};
    const stores = new Map();
    const keyOf = (request) => (typeof request === 'string' ? new URL(request, ORIGIN).href : request.url);
    const requests = [];
    let online = true;

    const fetchImpl = async (request) => {
        requests.push(typeof request === 'string' ? request : request.url);
        if (!online) { throw new TypeError('Failed to fetch'); }

        return network(typeof request === 'string' ? { url: new URL(request, ORIGIN).href, method: 'GET' } : request);
    };

    class FakeCache {
        constructor(map) { this.map = map; }
        async match(request) { const hit = this.map.get(keyOf(request)); return hit ? hit.clone() : undefined; }
        async put(request, response) { this.map.set(keyOf(request), response); }
        async add(request) { const response = await fetchImpl(request); if (!response.ok) { throw new Error('échec'); } await this.put(request, response); }
        async keys() { return [...this.map.keys()].map((url) => ({ url })); }
        async delete(request) { return this.map.delete(keyOf(request)); }
    }
    const caches = {
        open: async (name) => new FakeCache(stores.get(name) ?? stores.set(name, new Map()).get(name)),
        keys: async () => [...stores.keys()],
        delete: async (name) => stores.delete(name),
    };
    const self = {
        location: new URL('/sw.js', ORIGIN),
        addEventListener: (type, handler) => { handlers[type] = handler; },
        skipWaiting: async () => {},
        clients: { claim: async () => {} },
    };

    vm.runInContext(SOURCE, vm.createContext({ self, caches, fetch: fetchImpl, Response, Request, Headers, URL, Date, Promise, console, Array, String, Object, Math, Error, TypeError }));

    /** Déclenche un événement et attend ses promesses (respondWith, waitUntil). */
    async function dispatch(type, event) {
        const waits = [];
        let responded;
        const full = { ...event, respondWith: (promise) => { responded = promise; }, waitUntil: (promise) => { waits.push(promise); } };
        handlers[type](full);
        const response = responded ? await responded : null;
        await Promise.all(waits);

        return response;
    }

    return {
        stores,
        requests,
        setOnline: (value) => { online = value; },
        install: () => dispatch('install', {}),
        activate: () => dispatch('activate', {}),
        fetch: (request) => dispatch('fetch', { request }),
        /** Envoie un message et renvoie la réponse donnée sur le port. */
        async message(data) {
            let reply;
            await dispatch('message', { data, ports: [{ postMessage: (payload) => { reply = payload; } }] });

            // Les objets créés dans le contexte isolé ont leur propre prototype : on les ramène à des objets ordinaires.
            return reply === undefined ? undefined : JSON.parse(JSON.stringify(reply));
        },
        async pageKeys() { return stores.has('finora-pages-v1') ? [...stores.get('finora-pages-v1').keys()] : []; },
    };
}

/** Faux réseau : une page HTML pour tout, sauf quelques réponses particulières. */
const site = (overrides = {}) => (request) => {
    const path = new URL(request.url).pathname;
    if (overrides[path]) { return overrides[path](request); }
    if (path === '/offline.html') { return html('<html><head></head><body>Vous êtes hors ligne</body></html>'); }
    if (path.startsWith('/assets/')) { return new Response('body{}', { status: 200, headers: { 'Content-Type': 'text/css' } }); }

    return html(`<html><head><title>${path}</title></head><body>Page ${path}</body></html>`);
};

async function ready(network = site()) {
    const worker = createWorker(network);
    await worker.install();
    await worker.activate();

    return worker;
}

test('installation : la page hors ligne et les icônes sont gardées, la page hors ligne est indispensable', async () => {
    const worker = await ready();

    const cache = worker.stores.get('finora-static-v1');
    assert.ok(cache.has(`${ORIGIN}/offline.html`));
    assert.ok(cache.has(`${ORIGIN}/manifest.webmanifest`));

    const broken = createWorker((request) => (new URL(request.url).pathname === '/offline.html' ? new Response('', { status: 404 }) : html()));
    await assert.rejects(broken.install(), /hors ligne introuvable/);
});

test('activation : les anciennes versions des caches disparaissent, pas les caches d\'autres applications', async () => {
    const worker = createWorker(site());
    worker.stores.set('finora-pages-v0', new Map());
    worker.stores.set('finora-static-v0', new Map());
    worker.stores.set('autre-application', new Map());
    await worker.install();

    await worker.activate();

    assert.deepEqual([...worker.stores.keys()].filter((name) => name.endsWith('v0')), []);
    assert.ok(worker.stores.has('autre-application'));
});

test('ressources statiques : servies du cache après la première fois', async () => {
    const worker = await ready();
    const asset = req('/assets/styles/app-abc123.css', { mode: 'no-cors', accept: 'text/css,*/*' });

    const first = await worker.fetch(asset);
    const afterFirst = worker.requests.length;
    const second = await worker.fetch(asset);

    assert.equal(await first.text(), 'body{}');
    assert.equal(await second.text(), 'body{}');
    assert.equal(worker.requests.length, afterFirst, 'Le second appel n\'a pas touché le réseau');
});

test('polices Google : copie locale ; autres sites externes : ignorés', async () => {
    const worker = await ready((request) => (request.url.includes('fonts.googleapis.com') ? new Response('@font-face{}', { status: 200 }) : html()));

    const font = await worker.fetch({ url: 'https://fonts.googleapis.com/css2?family=Inter', method: 'GET', mode: 'no-cors', headers: new Headers({ Accept: 'text/css' }) });
    assert.equal(await font.text(), '@font-face{}');
    worker.setOnline(false);
    const offlineFont = await worker.fetch({ url: 'https://fonts.googleapis.com/css2?family=Inter', method: 'GET', mode: 'no-cors', headers: new Headers({ Accept: 'text/css' }) });
    assert.equal(await offlineFont.text(), '@font-face{}', 'Servie hors ligne depuis la copie');

    const foreign = await worker.fetch({ url: 'https://exemple.fr/pixel.png', method: 'GET', mode: 'no-cors', headers: new Headers({ Accept: 'image/*' }) });
    assert.equal(foreign, null, 'Le worker ne se mêle pas des autres sites');
});

test('mode désactivé (par défaut) : les pages passent par le réseau et rien n\'est gardé', async () => {
    const worker = await ready();

    const response = await worker.fetch(req('/transaction'));

    assert.equal(await response.text(), '<html><head><title>/transaction</title></head><body>Page /transaction</body></html>');
    assert.deepEqual(await worker.pageKeys(), []);
});

test('mode désactivé, hors ligne : la page d\'explication, avec le code 503', async () => {
    const worker = await ready();
    worker.setOnline(false);

    const response = await worker.fetch(req('/transaction'));

    assert.equal(response.status, 503);
    assert.match(await response.text(), /Vous êtes hors ligne/);
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
});

test('mode activé : les pages de consultation sont gardées après coup, avec leur date', async () => {
    const worker = await ready();
    await worker.message({ type: 'offline-mode', enabled: true });

    await worker.fetch(req('/transaction?type=expense'));

    assert.deepEqual(await worker.pageKeys(), [`${ORIGIN}/transaction?type=expense`]);
    const stored = worker.stores.get('finora-pages-v1').get(`${ORIGIN}/transaction?type=expense`);
    assert.match(stored.headers.get('X-Finora-Cached-At'), /^\d{4}-\d{2}-\d{2}T/);
});

test('mode activé, hors ligne : la copie est servie avec la balise « copie du… », sans toucher au réseau', async () => {
    const worker = await ready();
    await worker.message({ type: 'offline-mode', enabled: true });
    await worker.fetch(req('/account'));
    worker.setOnline(false);

    const response = await worker.fetch(req('/account'));
    const body = await response.text();

    assert.equal(response.status, 200);
    assert.match(body, /<head><meta name="finora-cached-at" content="\d{4}-\d{2}-\d{2}T[^"]+"><title>\/account<\/title>/);
    assert.match(body, /Page \/account/);
});

test('mode activé, hors ligne, page jamais gardée : la page d\'explication', async () => {
    const worker = await ready();
    await worker.message({ type: 'offline-mode', enabled: true });
    worker.setOnline(false);

    const response = await worker.fetch(req('/budget'));

    assert.equal(response.status, 503);
});

test('visite Turbo (GET qui demande du HTML sans être une navigation) : gardée et servie hors ligne comme une navigation', async () => {
    const worker = await ready();
    await worker.message({ type: 'offline-mode', enabled: true });
    const visit = req('/goal', { mode: 'cors', accept: 'text/html, application/xhtml+xml' });

    await worker.fetch(visit);
    worker.setOnline(false);
    const copy = await worker.fetch(visit);

    assert.equal(copy.status, 200);
    assert.match(await copy.text(), /finora-cached-at/);
});

/** @type {[string, boolean][]} */
const PATHS = [
    ['/', true], ['/account', true], ['/account/12', true], ['/transaction?type=expense&q=loyer', true], ['/budget', true], ['/goal/3', true],
    ['/stats', true], ['/calendar', true], ['/recurring', true], ['/transfer', true], ['/category', true], ['/settings', true],
    // Jamais gardés : formulaires, profil, authentification, imports, exports, actions, outils de développement.
    ['/transaction/new', false], ['/budget/5/edit', false], ['/goal/3/contribution/new', false],
    ['/profile', false], ['/profile/backup/download', false], ['/login', false], ['/register', false], ['/reset-password/reset/abc', false],
    ['/import/transactions', false], ['/import/transactions/map', false], ['/export/transactions', false], ['/bulk/transactions', false],
    ['/undo/0123456789abcdef', false], ['/logout', false], ['/_profiler/abc', false], ['/_wdt/abc', false], ['/autre-page', false],
];

for (const [path, cacheable] of PATHS) {
    test(`pages gardées : ${path} → ${cacheable ? 'oui' : 'non'}`, async () => {
        const worker = await ready();
        await worker.message({ type: 'offline-mode', enabled: true });

        await worker.fetch(req(path));

        assert.equal((await worker.pageKeys()).length, cacheable ? 1 : 0);
    });
}

test('pas de copie pour une réponse en erreur, sans HTML, ou marquée « no-store »', async () => {
    const worker = await ready(site({
        '/account': () => new Response('Erreur', { status: 500, headers: { 'Content-Type': 'text/html' } }),
        '/budget': () => new Response('{"a":1}', { status: 200, headers: { 'Content-Type': 'application/json' } }),
        '/goal': () => html('<html><head></head><body>secret</body></html>', { headers: { 'Cache-Control': 'no-store' } }),
        '/stats': () => new Response('<html></html>', { status: 200, headers: { 'Content-Type': 'text/html' }, }),
    }));
    await worker.message({ type: 'offline-mode', enabled: true });

    for (const path of ['/account', '/budget', '/goal']) {
        await worker.fetch(req(path));
    }

    assert.deepEqual(await worker.pageKeys(), []);
    await worker.fetch(req('/stats'));
    assert.equal((await worker.pageKeys()).length, 1, 'Une page normale est bien gardée');
});

test('désactiver le mode efface toutes les copies', async () => {
    const worker = await ready();
    await worker.message({ type: 'offline-mode', enabled: true });
    await worker.fetch(req('/account'));
    await worker.fetch(req('/budget'));
    assert.equal((await worker.pageKeys()).length, 2);

    const reply = await worker.message({ type: 'offline-mode', enabled: false });

    assert.deepEqual(reply, { ok: true, enabled: false });
    assert.deepEqual(await worker.pageKeys(), []);
    worker.setOnline(false);
    assert.equal((await worker.fetch(req('/account'))).status, 503, 'Hors ligne : plus de copie');
});

test('déconnexion : les copies sont effacées par le message de la page, et par la requête vers /logout ou /login', async () => {
    for (const trigger of [
        async (worker) => worker.message({ type: 'clear' }),
        async (worker) => worker.fetch(req('/logout')),
        async (worker) => worker.fetch(req('/login')),
    ]) {
        const worker = await ready();
        await worker.message({ type: 'offline-mode', enabled: true });
        await worker.fetch(req('/account'));
        assert.equal((await worker.pageKeys()).length, 1);

        await trigger(worker);

        assert.deepEqual(await worker.pageKeys(), []);
    }
});

test('session expirée : une visite redirigée vers /login efface les copies', async () => {
    const worker = await ready(site({
        '/account': () => {
            const response = html('<html><head></head><body>Connexion</body></html>');
            Object.defineProperty(response, 'redirected', { value: true });
            Object.defineProperty(response, 'url', { value: `${ORIGIN}/login` });

            return response;
        },
    }));
    await worker.message({ type: 'offline-mode', enabled: true });
    await worker.fetch(req('/budget'));
    assert.equal((await worker.pageKeys()).length, 1);

    await worker.fetch(req('/account', { mode: 'cors' }));

    assert.deepEqual(await worker.pageKeys(), []);
});

test('une action qui modifie des données hors ligne : page d\'explication en 503, rien en file d\'attente', async () => {
    const worker = await ready();

    const online = await worker.fetch(req('/bulk/transactions', { method: 'POST' }));
    assert.equal(online.status, 200, 'En ligne, la requête passe normalement');

    worker.setOnline(false);
    const offline = await worker.fetch(req('/bulk/transactions', { method: 'POST' }));
    assert.equal(offline.status, 503);
    assert.match(await offline.text(), /hors ligne/);
    // Les appels sans HTML (API, fetch de données) ne sont pas interceptés.
    assert.equal(await worker.fetch(req('/api/x', { method: 'POST', mode: 'cors', accept: 'application/json' })), null);
});

test('au plus 40 pages sont gardées : les plus anciennes disparaissent', async () => {
    const worker = await ready();
    await worker.message({ type: 'offline-mode', enabled: true });

    for (let i = 1; i <= 45; i++) {
        await worker.fetch(req(`/account/${i}`));
    }

    const keys = await worker.pageKeys();
    assert.equal(keys.length, 40);
    assert.ok(!keys.includes(`${ORIGIN}/account/1`));
    assert.ok(keys.includes(`${ORIGIN}/account/45`));
});

test('« warm » : copie les pages demandées, seulement celles qui sont gardables, et seulement si le mode est activé', async () => {
    const worker = await ready();
    const urls = ['/', '/account', '/profile', '/transaction/new', 'https://exemple.fr/account', '/budget'];

    assert.deepEqual(await worker.message({ type: 'warm', urls }), { ok: true, stored: 0 }, 'Mode désactivé : rien');
    await worker.message({ type: 'offline-mode', enabled: true });
    const reply = await worker.message({ type: 'warm', urls });

    assert.deepEqual(reply, { ok: true, stored: 3 });
    assert.deepEqual((await worker.pageKeys()).sort(), [`${ORIGIN}/`, `${ORIGIN}/account`, `${ORIGIN}/budget`]);
});

test('« status » : état du mode et nombre de pages gardées', async () => {
    const worker = await ready();
    assert.deepEqual(await worker.message({ type: 'status' }), { ok: true, enabled: false, pages: 0 });

    await worker.message({ type: 'offline-mode', enabled: true });
    await worker.fetch(req('/stats'));

    assert.deepEqual(await worker.message({ type: 'status' }), { ok: true, enabled: true, pages: 1 });
    assert.deepEqual(await worker.message({ type: 'inconnu' }), { ok: false });
});

test('la date de la copie est échappée avant d\'entrer dans la page', async () => {
    const worker = await ready();
    await worker.message({ type: 'offline-mode', enabled: true });
    await worker.fetch(req('/account'));
    // Une valeur piégée dans l'en-tête de la copie ne doit pas sortir de l'attribut.
    const stored = worker.stores.get('finora-pages-v1').get(`${ORIGIN}/account`);
    stored.headers.set('X-Finora-Cached-At', '"><script>alert(1)</script>');
    worker.setOnline(false);

    const body = await (await worker.fetch(req('/account'))).text();

    assert.ok(!body.includes('<script>alert(1)'));
    assert.match(body, /content="&quot;&gt;&lt;script&gt;/);
});
