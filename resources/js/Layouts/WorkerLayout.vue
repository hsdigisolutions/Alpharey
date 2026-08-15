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
import { canPromptInstall, isIos, isStandalone, promptInstall } from '@/pwa';
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
const showInstall = ref(false);
const showIosHint = ref(false);

function refreshInstallState() {
    if (isStandalone()) {
        showInstall.value = false;
        showIosHint.value = false;

        return;
    }
    showInstall.value = canPromptInstall();
    showIosHint.value = isIos();
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
});

onUnmounted(() => {
    window.clearInterval(clock);
    window.removeEventListener('pwa:installable', refreshInstallState);
    window.removeEventListener('pwa:installed', refreshInstallState);
});

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

            <!-- Install: a real prompt on Android, the manual route on iOS -->
            <div v-if="showInstall" class="mb-4 rounded-xl border border-line bg-surface-raised p-3 shadow-card">
                <p class="mb-2 text-sm text-ink-soft">{{ $t('worker.install_hint') }}</p>
                <VButton class="w-full" @click="install">{{ $t('worker.install') }}</VButton>
            </div>

            <div v-else-if="showIosHint"
                class="mb-4 rounded-xl border border-line bg-surface-raised p-3 text-xs text-ink-soft shadow-card">
                {{ $t('worker.install_ios') }}
            </div>

            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
