<script setup>
/**
 * The Worker PWA shell — deliberately NOT AppLayout.
 *
 * A worker holds a phone in one hand on a building site, often in gloves and
 * sunlight. So: one column, large touch targets, no sidebar, no navigation —
 * there is nowhere else for them to go — and a LIGHT theme forced on, because
 * a cream screen is far more readable in daylight than a dark one, and the
 * phone's own dark-mode setting must not turn the work app unreadable.
 *
 * A live clock and today's full date sit at the top: the one thing a worker
 * checks before punching is "what time is it, and is this today". They can
 * only ever act on today — every punch the server writes is dated now().
 *
 * Padding uses env(safe-area-inset-*) so the installed app clears an iPhone
 * notch and home indicator.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { canPromptInstall, isIos, isIosSafari, isStandalone, promptInstall } from '@/pwa';
import VButton from '@/Components/ui/VButton.vue';

defineProps({
    worker: { type: Object, required: true },
});

const page = usePage();
const primary = computed(() => page.props.locale?.primary ?? 'es');
const locale = computed(() => (primary.value === 'en' ? 'en-GB' : 'es-ES'));

// The worker app shows ONE language at a time (a stacked bilingual label is too
// cramped on a phone held in gloves), with this toggle to switch the whole app.
function setLang(lang) {
    if (lang !== primary.value) {
        router.post('/locale', { locale: lang }, { preserveScroll: true });
    }
}

// --- Live clock -------------------------------------------------------------
const now = ref(new Date());
let clock = null;

// The company operates in Spain: the clock is pinned to Europe/Madrid so it
// always matches the attendance times the server writes with now() — a worker
// (or a tester) on a device set to another timezone still sees Spanish time,
// not their own, which is the "when am I" that every punch is dated against.
const time = computed(() =>
    now.value.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Madrid' }),
);
const dateLine = computed(() =>
    now.value.toLocaleDateString(locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Europe/Madrid' }),
);

// --- Install prompt ---------------------------------------------------------
// showInstall = Android native prompt available. iosMode: 'safari' (show the
// Add-to-Home-Screen steps), 'other' (in-app/other iOS browser → open in
// Safari), or null. A worker can close the banner for the SESSION only
// (sessionStorage) — it returns next time they open the tab, and never at all
// once the app is installed (standalone).
const DISMISS_KEY = 'pwa_install_dismissed';
const showInstall = ref(false);
const iosMode = ref(null);
const bannerDismissed = ref(sessionStorageGet(DISMISS_KEY) === '1');

function sessionStorageGet(k) {
    try { return window.sessionStorage.getItem(k); } catch { return null; }
}
function dismissBanner() {
    bannerDismissed.value = true;
    try { window.sessionStorage.setItem(DISMISS_KEY, '1'); } catch { /* private mode: session-only in memory */ }
}

const showInstallBanner = computed(() =>
    ! bannerDismissed.value && (showInstall.value || iosMode.value !== null));

function refreshInstallState() {
    if (isStandalone()) { // installed → never nag
        showInstall.value = false;
        iosMode.value = null;

        return;
    }
    showInstall.value = canPromptInstall();
    iosMode.value = isIos() ? (isIosSafari() ? 'safari' : 'other') : null;
}

onMounted(() => {
    // Force the LIGHT theme for the worker app, whatever the phone or the CRM
    // toggle last set — a builder in sunlight needs the cream screen, not dark.
    document.documentElement.classList.remove('dark');

    // Tick the clock. Aligning to the top of the minute would be neater, but a
    // plain 1s interval keeps the seconds-free display honest with no drift.
    clock = window.setInterval(() => { now.value = new Date(); }, 1000);

    refreshInstallState();
    window.addEventListener('pwa:installable', refreshInstallState);
    window.addEventListener('pwa:installed', refreshInstallState);
    document.addEventListener('visibilitychange', refreshWorkerOnForeground);
});

onUnmounted(() => {
    window.clearInterval(clock);
    window.removeEventListener('pwa:installable', refreshInstallState);
    window.removeEventListener('pwa:installed', refreshInstallState);
    document.removeEventListener('visibilitychange', refreshWorkerOnForeground);
});

// A standalone PWA (iOS especially) RESUMES rather than reloads when reopened,
// so the page props loaded when the app first opened — including which company
// the worker belongs to — can go stale after a transfer. Re-fetch just the
// identity/branding prop when the app regains focus so "the next time they open
// it" always shows the CURRENT company (the server already flips company_id on
// transfer). Only `worker` is refetched, so an in-progress punch is untouched.
function refreshWorkerOnForeground() {
    if (document.visibilityState === 'visible') {
        router.reload({ only: ['worker'], preserveScroll: true, preserveState: true });
    }
}

async function install() {
    await promptInstall();
    refreshInstallState();
}

function logout() {
    // replace:true so the signed-out state takes the current history entry —
    // the phone's back button must not walk back into the app after logout.
    router.post('/logout', {}, { replace: true });
}
</script>

<template>
    <div class="min-h-screen bg-surface text-ink" :dir="primary === 'ur' ? 'rtl' : 'ltr'"
        style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom)">
        <div class="mx-auto my-4 w-full max-w-md rounded-2xl border border-line bg-surface px-4 py-5 shadow-card sm:my-6">
            <!-- Identity + logout -->
            <header class="mb-4 flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-accent text-sm font-bold text-on-accent shadow-sm">AR</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-base font-semibold leading-tight">{{ worker.name }}</p>
                    <p class="truncate text-xs text-ink-soft">
                        {{ worker.code }}<template v-if="worker.company"> · {{ worker.company }}</template>
                    </p>
                </div>
                <!-- Language switch: ES · EN · UR (Urdu, worker PWA only) -->
                <div class="flex shrink-0 overflow-hidden rounded-lg border border-line text-xs font-semibold">
                    <button type="button" class="px-2.5 py-1.5 transition"
                        :class="primary === 'es' ? 'bg-accent text-on-accent' : 'text-ink-soft active:bg-surface-hover'"
                        @click="setLang('es')">ES</button>
                    <button type="button" class="px-2.5 py-1.5 transition"
                        :class="primary === 'en' ? 'bg-accent text-on-accent' : 'text-ink-soft active:bg-surface-hover'"
                        @click="setLang('en')">EN</button>
                    <button type="button" class="px-2.5 py-1.5 transition"
                        :class="primary === 'ur' ? 'bg-accent text-on-accent' : 'text-ink-soft active:bg-surface-hover'"
                        @click="setLang('ur')">UR</button>
                </div>
                <button type="button"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-line bg-surface-raised text-ink-soft transition active:scale-95 active:bg-surface-hover"
                    :aria-label="$t('common.logout')"
                    @click="logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                        stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <polyline points="16 17 21 12 16 7" />
                        <line x1="21" y1="12" x2="9" y2="12" />
                    </svg>
                </button>
            </header>

            <!-- Live clock + today's date: the "when am I" a worker checks
                 before punching. This is the only day they can act on. -->
            <div class="mb-5 rounded-2xl bg-sidebar px-5 py-4 text-center shadow-card">
                <p class="tabular-nums text-4xl font-semibold tracking-tight text-white">{{ time }}</p>
                <p class="mt-1 text-sm capitalize text-white/70">{{ dateLine }}</p>
            </div>

            <!-- Install banner — prominent, returns each session until installed -->
            <div v-if="showInstallBanner" class="mb-4 overflow-hidden rounded-2xl border border-accent/40 bg-accent-soft shadow-card">
                <div class="flex items-start gap-3 p-4">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-accent text-on-accent shadow-sm">
                        <!-- app / install glyph -->
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                            <path d="M12 3v12" /><path d="m7 10 5 5 5-5" /><path d="M5 21h14" />
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-ink">{{ $t('worker.install_title') }}</p>

                        <!-- Android: one-tap native install -->
                        <template v-if="showInstall">
                            <p class="mt-0.5 text-xs text-ink-soft">{{ $t('worker.install_hint') }}</p>
                            <VButton class="mt-2 w-full" @click="install">{{ $t('worker.install') }}</VButton>
                        </template>

                        <!-- iOS Safari: manual Add to Home Screen, with the share glyph inline -->
                        <template v-else-if="iosMode === 'safari'">
                            <p class="mt-1 flex flex-wrap items-center gap-1 text-xs text-ink-soft">
                                <span>{{ $t('worker.install_ios_1') }}</span>
                                <!-- iOS share icon: box with up arrow -->
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="inline h-4 w-4 align-text-bottom text-accent">
                                    <path d="M12 15V4" /><path d="m8 8 4-4 4 4" /><path d="M8 11H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-2" />
                                </svg>
                                <span>{{ $t('worker.install_ios_2') }}</span>
                            </p>
                        </template>

                        <!-- iOS but NOT Safari (in-app webview / other browser): can't install here -->
                        <template v-else-if="iosMode === 'other'">
                            <p class="mt-0.5 text-xs text-ink-soft">{{ $t('worker.install_open_safari') }}</p>
                        </template>
                    </div>

                    <button type="button" class="shrink-0 rounded-lg p-1 text-ink-soft transition active:scale-95 active:bg-surface-hover"
                        :aria-label="$t('common.close')" @click="dismissBanner">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" class="h-4 w-4">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
