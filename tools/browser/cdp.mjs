// Pilote minimal de Chrome / Chromium / Edge par le protocole DevTools (CDP), sans dépendance :
// navigation, connexion, captures d'écran, souris, clavier, évaluation de JavaScript.
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const ROOT = resolve(import.meta.dirname, '..', '..');

/** Navigateur à utiliser : variable CHROME_PATH, sinon les emplacements habituels (Chrome, Edge, Chromium). */
export function findBrowser() {
    const candidates = [
        process.env.CHROME_PATH,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        `${process.env.LOCALAPPDATA}\\Google\\Chrome\\Application\\chrome.exe`,
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
    ].filter(Boolean);

    return candidates.find((path) => existsSync(path)) ?? null;
}

/** Identifiants de l'utilisateur de test, lus dans les fixtures (jamais recopiés ailleurs). */
export function testCredentials() {
    const source = readFileSync(join(ROOT, 'src', 'DataFixtures', 'TestUserFixtures.php'), 'utf8');

    return {
        email: source.match(/public const EMAIL = '([^']+)'/)[1],
        password: source.match(/PASSWORD = '([^']+)'/)[1],
    };
}

export async function launch({ port = 9340 + Math.floor(Math.random() * 500) } = {}) {
    const executable = findBrowser();
    if (!executable) {
        throw new Error('Aucun navigateur trouvé : installez Chrome, Edge ou Chromium, ou définissez CHROME_PATH.');
    }

    const profile = mkdtempSync(join(tmpdir(), 'finora-browser-'));
    const process_ = spawn(executable, [
        '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--hide-scrollbars',
        '--force-color-profile=srgb', '--window-size=1400,900', 'about:blank',
    ], { stdio: 'ignore' });

    let target;
    for (let i = 0; i < 60 && !target; i++) {
        await sleep(200);
        try {
            target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === 'page');
        } catch { /* le navigateur démarre */ }
    }
    if (!target) {
        process_.kill();
        throw new Error('Le navigateur ne répond pas.');
    }

    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolveOpen, reject) => { socket.onopen = resolveOpen; socket.onerror = reject; });

    let counter = 0;
    const pending = new Map();
    const pageErrors = [];
    socket.onmessage = (event) => {
        const message = JSON.parse(event.data);
        if (message.id && pending.has(message.id)) {
            const { resolve: ok, reject } = pending.get(message.id);
            pending.delete(message.id);
            message.error ? reject(new Error(message.error.message)) : ok(message.result);
        } else if (message.method === 'Runtime.exceptionThrown') {
            pageErrors.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
        }
    };
    const send = (method, params = {}) => new Promise((ok, reject) => {
        const id = ++counter;
        pending.set(id, { resolve: ok, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });
    for (const domain of ['Page', 'Runtime', 'Network']) { await send(`${domain}.enable`); }

    const api = {
        send,
        pageErrors,
        clearErrors() { pageErrors.length = 0; },
        close() {
            try { socket.close(); } catch { /* déjà fermé */ }
            process_.kill();
            try { rmSync(profile, { recursive: true, force: true }); } catch { /* verrou Windows : sans importance */ }
        },

        async goto(url, settleMs = 600) {
            await send('Page.navigate', { url });
            for (let i = 0; i < 100; i++) {
                await sleep(100);
                if ((await send('Runtime.evaluate', { expression: 'document.readyState' })).result.value === 'complete') { break; }
            }
            await sleep(settleMs);
        },

        async eval(expression) {
            const { result, exceptionDetails } = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
            if (exceptionDetails) { throw new Error(exceptionDetails.exception?.description ?? exceptionDetails.text); }

            return result.value;
        },

        /** Taille d'écran, tactile, thème et préférence « réduire les animations » du système. */
        async viewport({ width = 1400, height = 900, mobile = false, dark = false, reduceMotion = false } = {}) {
            await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: mobile ? 2 : 1, mobile });
            await send('Emulation.setTouchEmulationEnabled', { enabled: mobile });
            await send('Emulation.setEmulatedMedia', { features: [
                { name: 'prefers-color-scheme', value: dark ? 'dark' : 'light' },
                { name: 'prefers-reduced-motion', value: reduceMotion ? 'reduce' : 'no-preference' },
            ] });
        },

        async screenshot(file) {
            writeFileSync(file, Buffer.from((await send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
        },

        rect(selector) {
            return api.eval(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); if (!e) return null; const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height, cx: r.x + r.width / 2, cy: r.y + r.height / 2 }; })()`);
        },
        move: (x, y) => send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y }),
        async click(x, y) {
            await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
            await send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
            await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
        },
        async key(key) {
            await send('Input.dispatchKeyEvent', { type: 'keyDown', key, code: key });
            await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code: key });
        },

        async login(base, email, password) {
            await send('Network.clearBrowserCookies');
            await api.goto(`${base}/login`, 400);
            await api.eval(`(() => {
                document.querySelector('#username').value = ${JSON.stringify(email)};
                document.querySelector('#password').value = ${JSON.stringify(password)};
                document.querySelector('form.auth-form').requestSubmit();
            })()`);
            // La redirection peut prendre plusieurs secondes sur un serveur de développement « froid ».
            for (let i = 0; i < 40; i++) {
                await sleep(250);
                if ((await api.eval('location.pathname')) !== '/login') { return; }
            }
            throw new Error('Connexion refusée : l\'utilisateur de test existe-t-il ? (symfony console doctrine:fixtures:load --group=test --append)');
        },
    };

    return api;
}
