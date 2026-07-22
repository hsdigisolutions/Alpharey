/**
 * AlphaRey Worker PWA — service worker.
 *
 * Registered with scope "/worker" only, so it never controls a CRM page: an
 * office user must always get fresh markup, and a stale-asset bug on the
 * payroll screen would be far worse than a slow first paint.
 *
 * Offline check-in is deliberately OUT of scope for v1 (client decision), so
 * this deliberately does NOT queue or replay punches — a punch that appears to
 * succeed offline and silently fails later is worse than an honest "no
 * connection" message. Its job is narrower: make the shell load instantly on a
 * bad site connection, and show a clear offline page instead of the browser's
 * dinosaur when the network is gone.
 *
 * Bump CACHE_VERSION to retire every old cache on the next deploy.
 */
const CACHE_VERSION = 'alpharey-worker-v1';
const OFFLINE_URL = '/worker/offline';

// The shell: enough to paint something branded without the network.
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png', '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_VERSION)
            // Individually, so one 404 cannot fail the whole install and leave
            // the app with no service worker at all.
            .then((cache) => Promise.allSettled(PRECACHE.map((url) => cache.add(url))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only GET is ever cached or retried. A check-in POST must reach the
    // server or fail loudly — never be served from a cache.
    if (request.method !== 'GET') {
        return;
    }

    // Navigations: network first, offline page as the fallback. Never serve a
    // cached HTML page — the worker's own figures must always be live.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));

        return;
    }

    // Static build assets are content-hashed, so a cache hit is always the
    // right file: serve from cache, fall back to network and store.
    const url = new URL(request.url);
    const isAsset = url.origin === self.location.origin
        && (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/'));

    if (isAsset) {
        event.respondWith(
            caches.match(request).then(
                (hit) => hit
                    || fetch(request).then((response) => {
                        const copy = response.clone();
                        caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));

                        return response;
                    }),
            ),
        );
    }
});
