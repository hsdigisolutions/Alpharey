/**
 * Worker PWA registration + install prompt plumbing.
 *
 * The service worker is registered ONLY on worker pages and scoped to
 * "/worker", so a CRM user never gets a service worker at all — an office
 * screen must always fetch fresh markup, and a stale-asset bug on the payroll
 * screen would be far worse than a slow first paint.
 */

/** Chrome/Android fires this; Safari/iOS never does. */
let deferredInstallPrompt = null;

export function initPwa() {
    if (!('serviceWorker' in navigator)) return;
    if (!window.location.pathname.startsWith('/worker')) return;

    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/worker' })
            // A failed registration must never break the app — the worker can
            // still check in perfectly well online without a service worker.
            .catch(() => {});
    });
}

window.addEventListener('beforeinstallprompt', (event) => {
    // Stop Chrome's own mini-infobar so we can offer install where it makes
    // sense in the UI instead.
    event.preventDefault();
    deferredInstallPrompt = event;
    window.dispatchEvent(new CustomEvent('pwa:installable'));
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    window.dispatchEvent(new CustomEvent('pwa:installed'));
});

export function canPromptInstall() {
    return deferredInstallPrompt !== null;
}

/**
 * Show the native install dialog (Android/Chrome only).
 *
 * @returns {Promise<boolean>} true when the user accepted
 */
export async function promptInstall() {
    if (!deferredInstallPrompt) return false;

    deferredInstallPrompt.prompt();
    const { outcome } = await deferredInstallPrompt.userChoice;
    deferredInstallPrompt = null;

    return outcome === 'accepted';
}

/** Already running from the home screen? Then hide any install hint. */
export function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches
        // iOS predates display-mode and exposes its own flag instead.
        || window.navigator.standalone === true;
}

export function isIos() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent);
}
