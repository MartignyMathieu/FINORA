/*
 * Service worker de FINORA : mode hors ligne.
 *
 * Trois rôles, du plus sûr au plus sensible :
 *
 * 1. Toujours : mettre en cache les ressources statiques (feuilles de style, scripts, icônes, polices : /assets, /icons, manifeste) pour que
 *    l'application démarre vite, et afficher une page d'explication (/offline.html) au lieu de l'erreur du navigateur quand le réseau
 *    est indisponible. Une action qui modifie des données (POST) hors ligne reçoit cette page avec le code 503 : rien n'est mis en file d'attente.
 *
 * 2. Seulement si l'utilisateur l'a activé (Paramètres → Mode hors ligne) : garder une copie de ses dernières pages de consultation pour les
 *    afficher en lecture seule sans réseau, avec un bandeau « copie du … » (meta finora-cached-at). Ces copies contiennent des données financières ;
 *    elles ne concernent que quelques sections (voir PAGE_PREFIXES), jamais le profil, la connexion, les imports ni les exports, sont limitées
 *    à MAX_PAGES et sont EFFACÉES à la déconnexion, à l'arrivée sur la page de connexion, quand la session a expiré (redirection vers /login)
 *    et quand l'option est désactivée.
 *
 * 3. Les polices Google (Inter, Montserrat) sont gardées en cache pour que la mise en page reste la même hors ligne.
 *
 * Aucune donnée n'est jamais envoyée ailleurs : tout reste dans le Cache Storage de ce navigateur.
 */
'use strict';

const VERSION = 'v1';
const STATIC_CACHE = `finora-static-${VERSION}`;
const PAGES_CACHE = `finora-pages-${VERSION}`;
const META_CACHE = `finora-meta-${VERSION}`;

const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png', '/manifest.webmanifest'];
const MODE_KEY = '/__finora/offline-mode';
const CACHED_AT_HEADER = 'X-Finora-Cached-At';
const MAX_PAGES = 40;
const FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];

/** Sections dont les pages de consultation peuvent être gardées (mode hors ligne activé). */
const PAGE_PREFIXES = ['/account', '/transaction', '/budget', '/goal', '/stats', '/calendar', '/recurring', '/transfer', '/category', '/settings'];
/** Jamais gardés, même sous un préfixe autorisé : profil, authentification, imports, exports, actions. */
const NEVER_CACHE = ['/profile', '/login', '/register', '/reset-password', '/import', '/export', '/bulk', '/undo', '/logout', '/_'];

// ---------------------------------------------------------------------------------------------- règles (sans effet de bord)

/** Ressource statique et versionnée (ou presque) : on la sert du cache. */
function isStaticPath(pathname) {
    return pathname.startsWith('/assets/') || pathname.startsWith('/icons/') || pathname === '/manifest.webmanifest' || pathname === OFFLINE_URL;
}

/** Page de consultation que l'on peut garder pour le mode hors ligne. */
function isCacheablePage(pathname) {
    if (NEVER_CACHE.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`) || (prefix === '/_' && pathname.startsWith('/_')))) {
        return false;
    }
    // Les formulaires (création, modification, versement…) n'ont aucun intérêt hors ligne.
    if (/\/(new|edit)(\/|$)/.test(pathname) || pathname.includes('/contribution')) {
        return false;
    }

    return pathname === '/' || PAGE_PREFIXES.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}

/** Requête de page : navigation du navigateur, ou visite Turbo (GET qui demande du HTML). */
function isPageRequest(request) {
    return request.mode === 'navigate' || (request.headers.get('Accept') || '').includes('text/html');
}

/** Clé de cache d'une page : adresse sans fragment. */
function pageKey(url) {
    return `${url.origin}${url.pathname}${url.search}`;
}

function escapeAttribute(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** Insère la balise qui signale une copie (lue par le bandeau « hors ligne » de la page). */
function markCached(html, cachedAt) {
    const meta = `<meta name="finora-cached-at" content="${escapeAttribute(cachedAt)}">`;

    return /<head[^>]*>/i.test(html) ? html.replace(/<head[^>]*>/i, (open) => open + meta) : meta + html;
}

// ---------------------------------------------------------------------------------------------- état

async function isOfflineModeEnabled() {
    const cache = await caches.open(META_CACHE);
    const stored = await cache.match(MODE_KEY);

    return stored ? (await stored.text()) === '1' : false;
}

async function setOfflineMode(enabled) {
    const cache = await caches.open(META_CACHE);
    await cache.put(MODE_KEY, new Response(enabled ? '1' : '0'));
    if (!enabled) {
        await clearPages();
    }
}

async function clearPages() {
    await caches.delete(PAGES_CACHE);
}

// ---------------------------------------------------------------------------------------------- pages gardées

/** Garde une page (la réponse passée est consommée : fournir un clone si elle sert aussi ailleurs). */
async function storePage(key, response) {
    const body = await response.blob();
    const headers = new Headers(response.headers);
    // Le corps a déjà été décodé par le navigateur : ces en-têtes ne sont plus vrais.
    headers.delete('Content-Encoding');
    headers.delete('Content-Length');
    headers.set(CACHED_AT_HEADER, new Date().toISOString());

    const cache = await caches.open(PAGES_CACHE);
    await cache.put(key, new Response(body, { status: response.status, statusText: response.statusText, headers }));

    // On ne garde que les MAX_PAGES dernières pages (les plus anciennes d'abord).
    const keys = await cache.keys();
    for (const old of keys.slice(0, Math.max(0, keys.length - MAX_PAGES))) {
        await cache.delete(old);
    }
}

async function readCachedPage(key) {
    const cache = await caches.open(PAGES_CACHE);
    const hit = await cache.match(key);
    if (!hit) {
        return null;
    }

    const html = markCached(await hit.text(), hit.headers.get(CACHED_AT_HEADER) || '');
    const headers = new Headers(hit.headers);
    headers.set('Content-Type', 'text/html; charset=UTF-8');

    return new Response(html, { status: 200, headers });
}

async function offlineResponse() {
    const cache = await caches.open(STATIC_CACHE);
    const page = await cache.match(OFFLINE_URL);
    const body = page ? await page.text() : '<!doctype html><meta charset="utf-8"><title>Hors ligne</title><h1>Vous êtes hors ligne</h1>';

    return new Response(body, { status: 503, headers: { 'Content-Type': 'text/html; charset=UTF-8', 'Cache-Control': 'no-store' } });
}

/** Page (GET) : le réseau d'abord ; hors ligne, la copie si le mode est activé, sinon la page d'explication. */
async function handlePage(request, url, event) {
    try {
        const response = await fetch(request);

        // Une redirection suivie vers la connexion (visite Turbo) : la session a expiré, les copies ne doivent plus être lisibles.
        // (Pour une navigation du navigateur, la redirection n'est pas visible ici ; l'arrivée sur /login efface les copies.)
        if (response.redirected && new URL(response.url).pathname === '/login') {
            await clearPages();

            return response;
        }

        const cacheControl = response.headers.get('Cache-Control') || '';
        if (response.status === 200 && !response.redirected && isCacheablePage(url.pathname) && !cacheControl.includes('no-store')
            && (response.headers.get('Content-Type') || '').includes('text/html') && await isOfflineModeEnabled()) {
            // La copie est écrite après coup : elle ne retarde pas l'affichage de la page.
            const copy = response.clone();
            event.waitUntil(storePage(pageKey(url), copy));
        }

        return response;
    } catch {
        if (isCacheablePage(url.pathname) && await isOfflineModeEnabled()) {
            const copy = await readCachedPage(pageKey(url));
            if (copy) {
                return copy;
            }
        }

        return offlineResponse();
    }
}

// ---------------------------------------------------------------------------------------------- ressources statiques

async function cacheFirst(request) {
    const cache = await caches.open(STATIC_CACHE);
    const hit = await cache.match(request);
    if (hit) {
        return hit;
    }
    const response = await fetch(request);
    if (response.ok) {
        await cache.put(request, response.clone());
    }

    return response;
}

/** Sert la copie tout de suite et la rafraîchit en arrière-plan (polices, icônes, manifeste). */
async function staleWhileRevalidate(request, event) {
    const cache = await caches.open(STATIC_CACHE);
    const hit = await cache.match(request);
    const refresh = fetch(request).then(async (response) => {
        // Les réponses « opaques » (polices Google chargées sans CORS) ont le statut 0 : on les garde aussi.
        if (response.ok || response.type === 'opaque') {
            await cache.put(request, response.clone());
        }

        return response;
    });

    if (hit) {
        event.waitUntil(refresh.catch(() => undefined));

        return hit;
    }

    return refresh;
}

// ---------------------------------------------------------------------------------------------- événements

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(STATIC_CACHE);
        await Promise.all(PRECACHE.map(async (path) => {
            try {
                await cache.add(path);
            } catch {
                // Seule la page hors ligne est indispensable ; les autres se mettront en cache à l'usage.
                if (path === OFFLINE_URL) {
                    throw new Error('Page hors ligne introuvable');
                }
            }
        }));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        // Anciennes versions des caches.
        const current = [STATIC_CACHE, PAGES_CACHE, META_CACHE];
        for (const name of await caches.keys()) {
            if (name.startsWith('finora-') && !current.includes(name)) {
                await caches.delete(name);
            }
        }
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Polices Google : copie locale.
    if (url.origin !== self.location.origin) {
        if (request.method === 'GET' && FONT_HOSTS.includes(url.hostname)) {
            event.respondWith(staleWhileRevalidate(request, event));
        }

        return;
    }

    // Une action qui modifie des données (formulaire) sans réseau : on l'explique, rien n'est enregistré ni mis en attente.
    if (request.method !== 'GET') {
        if (isPageRequest(request)) {
            event.respondWith(fetch(request).catch(() => offlineResponse()));
        }

        return;
    }

    // Quitter ou arriver sur la connexion : plus aucune copie lisible.
    if (url.pathname === '/logout' || url.pathname === '/login') {
        event.waitUntil(clearPages());
    }

    if (isStaticPath(url.pathname)) {
        event.respondWith(url.pathname.startsWith('/assets/') ? cacheFirst(request) : staleWhileRevalidate(request, event));

        return;
    }

    if (isPageRequest(request)) {
        event.respondWith(handlePage(request, url, event));
    }
});

/**
 * Messages de la page : { type: 'offline-mode', enabled }, { type: 'clear' }, { type: 'warm', urls }, { type: 'status' }.
 * Si la page fournit un port (MessageChannel), la réponse y est envoyée.
 */
self.addEventListener('message', (event) => {
    const data = event.data || {};
    const reply = (payload) => event.ports && event.ports[0] && event.ports[0].postMessage(payload);

    event.waitUntil((async () => {
        switch (data.type) {
            case 'offline-mode':
                await setOfflineMode(true === data.enabled);
                reply({ ok: true, enabled: true === data.enabled });
                break;
            case 'clear':
                await clearPages();
                reply({ ok: true });
                break;
            case 'warm': {
                let stored = 0;
                if (await isOfflineModeEnabled()) {
                    for (const path of Array.isArray(data.urls) ? data.urls.slice(0, MAX_PAGES) : []) {
                        const url = new URL(String(path), self.location.origin);
                        if (url.origin !== self.location.origin || !isCacheablePage(url.pathname)) {
                            continue;
                        }
                        try {
                            const response = await fetch(url.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
                            if (response.status === 200 && !response.redirected && (response.headers.get('Content-Type') || '').includes('text/html')) {
                                await storePage(pageKey(url), response);
                                ++stored;
                            }
                        } catch {
                            // Hors ligne ou page indisponible : on passe à la suivante.
                        }
                    }
                }
                reply({ ok: true, stored });
                break;
            }
            case 'status': {
                const cache = await caches.open(PAGES_CACHE);
                reply({ ok: true, enabled: await isOfflineModeEnabled(), pages: (await cache.keys()).length });
                break;
            }
            default:
                reply({ ok: false });
        }
    })());
});
