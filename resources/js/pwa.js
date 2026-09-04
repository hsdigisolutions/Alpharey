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

/**
 * On iOS, "Add to Home Screen" exists ONLY in real Safari. Chrome/Firefox/Edge
 * on iOS (CriOS/FxiOS/EdgiOS/OPiOS) and in-app webviews (WhatsApp, Instagram,
 * Facebook, Gmail…) cannot install — a worker following the instructions there
 * finds no option, which is the usual "it doesn't work on iPhone" report. Real
 * Safari's UA has both "Version/" and "Safari" and none of the other-browser
 * tokens.
 */
export function isIosSafari() {
    if (!isIos()) return false;
    const ua = window.navigator.userAgent || '';
    const otherBrowser = /CriOS|FxiOS|EdgiOS|EdgA|OPiOS|mercury/i.test(ua);
    return /Version\//i.test(ua) && /Safari/i.test(ua) && ! otherBrowser;
}

/**
 * Running inside an in-app webview (WhatsApp, Instagram, Facebook, Messenger,
 * Line, Gmail…) rather than a real browser. These cannot install a PWA on
 * EITHER platform — the only path is "open in the real browser first". Used to
 * show the copy-link / open-in-Safari branch of the install gate.
 */
export function isInAppBrowser() {
    const ua = window.navigator.userAgent || '';
    return /FBAN|FBAV|FB_IAB|Instagram|Line\/|MicroMessenger|WhatsApp|GSA\/|EdgiOS/i.test(ua)
        // A generic iOS webview: iOS, not standalone, and lacks Safari's own tokens.
        || (isIos() && ! isIosSafari() && ! /CriOS|FxiOS|OPiOS/i.test(ua));
}
