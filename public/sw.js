/*
 * Service worker for the member PWA (área de socio/a).
 *
 * Scope note: this file lives at the site root, so it is registered with the
 * default "/" scope. It is ONLY ever registered from the member layout, and the
 * fetch handler deliberately IGNORES every request outside the member area
 * (and the shell assets it needs) — so the Filament admin at "/" is never
 * intercepted or cached. No staff/admin page is ever served from this cache.
 *
 * Strategy:
 *   - the home card page  -> cache-first (stale-while-revalidate) so the QR
 *     membership card renders offline; a background fetch keeps it fresh online.
 *   - other member pages  -> network-first, falling back to cache, then to the
 *     cached card, then to a minimal inline offline page.
 *   - shell assets (built CSS/JS/fonts, icons) -> cache-first (SWR).
 *   - the RGPD data export and any non-GET -> never cached.
 */

const VERSION = 'socio-v1';
const CACHE = `socio-shell-${VERSION}`;

const HOME_PATH = '/socio';
// Member navigations we are happy to serve from cache when offline.
const CACHEABLE_NAV = ['/socio', '/socio/', '/socio/menu', '/socio/historial', '/socio/avisos', '/socio/eventos', '/socio/notificaciones'];
// Never cache the data export (a personal download) or auth transitions.
const NEVER_CACHE = ['/socio/mis-datos', '/socio/login', '/socio/logout'];

const OFFLINE_HTML = `<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sin conexión</title>
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
font-family:Inter,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#0f172a;color:#e2e8f0}
.c{max-width:22rem;text-align:center;padding:2rem}h1{color:#2563eb;font-size:1.25rem;margin:0 0 .5rem}
p{color:#94a3b8;line-height:1.5}</style></head>
<body><div class="c"><h1>Sin conexión</h1>
<p>No hay conexión. Tu carné de socio/a guardado sigue disponible desde la pantalla de inicio.</p></div></body></html>`;

function isShellAsset(url) {
    return url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/fonts/') ||
        url.pathname.startsWith('/socio-icons/') ||
        url.pathname === '/manifest.webmanifest';
}

function isHome(url) {
    return url.pathname === HOME_PATH || url.pathname === HOME_PATH + '/';
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll([
            '/socio-icons/icon-192.png',
            '/socio-icons/icon-512.png',
            '/manifest.webmanifest',
        ]).catch(() => undefined)),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

async function staleWhileRevalidate(request) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(request);
    const network = fetch(request)
        .then((response) => {
            if (response && response.ok) {
                cache.put(request, response.clone());
            }
            return response;
        })
        .catch(() => undefined);

    return cached || network || Promise.reject(new Error('no-source'));
}

async function networkFirst(request, url) {
    const cache = await caches.open(CACHE);
    try {
        const response = await fetch(request);
        if (response && response.ok && CACHEABLE_NAV.includes(url.pathname)) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (e) {
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }
        const home = await cache.match(HOME_PATH);
        if (home) {
            return home;
        }
        return new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return; // never intercept POST (push subscribe, RSVP, logout…)
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return; // cross-origin: leave to the browser
    }

    // Shell assets used by the member area (also requested by admin — harmless to serve cached).
    if (isShellAsset(url)) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }

    // Everything outside the member area is left entirely to the browser.
    if (!url.pathname.startsWith('/socio')) {
        return;
    }

    if (NEVER_CACHE.includes(url.pathname)) {
        return; // exports / auth: always straight to the network
    }

    // The card page: cache-first so it renders with no network.
    if (isHome(url)) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }

    // Other member navigations: fresh when online, cached when not.
    if (request.mode === 'navigate' || CACHEABLE_NAV.includes(url.pathname)) {
        event.respondWith(networkFirst(request, url));
    }
});

// Show a notification pushed from the server (webpush payload = the message array).
self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { title: 'Aviso', body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Aviso del club';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/socio-icons/icon-192.png',
        badge: '/socio-icons/icon-192.png',
        data: payload.data || { url: '/socio' },
        tag: payload.tag,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/socio';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if (client.url.includes('/socio') && 'focus' in client) {
                    return client.focus();
                }
            }
            return self.clients.openWindow(target);
        }),
    );
});
