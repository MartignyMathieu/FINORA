/*
 * Mode hors ligne : enregistrement du service worker (public/sw.js).
 *
 * Il ne fonctionne qu'en HTTPS (ou sur localhost) ; ailleurs, l'application marche normalement, simplement sans mode hors ligne.
 * À la déconnexion, les copies de pages gardées pour la consultation hors ligne sont effacées tout de suite.
 */
export function isSupported() {
    return 'serviceWorker' in navigator && window.isSecureContext;
}

/** Envoie un message au service worker actif et renvoie sa réponse (null s'il n'y en a pas ou s'il ne répond pas). */
export async function askWorker(message, timeout = 15000) {
    if (!isSupported()) {
        return null;
    }
    const registration = await navigator.serviceWorker.ready;
    const worker = registration.active;
    if (!worker) {
        return null;
    }

    return new Promise((resolve) => {
        const channel = new MessageChannel();
        const timer = setTimeout(() => resolve(null), timeout);
        channel.port1.onmessage = (event) => {
            clearTimeout(timer);
            resolve(event.data);
        };
        worker.postMessage(message, [channel.port2]);
    });
}

if (isSupported()) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // Échec silencieux : l'application reste utilisable sans mode hors ligne.
        });
    });

    // Se déconnecter efface les copies de pages avant même que le serveur ne réponde.
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (link && new URL(link.href, location.href).pathname === '/logout') {
            navigator.serviceWorker.controller?.postMessage({ type: 'clear' });
        }
    });
}
