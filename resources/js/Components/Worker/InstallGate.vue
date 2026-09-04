<script setup>
/**
 * Full-screen BLOCKING install gate for the worker PWA. Shown whenever the app
 * is NOT running standalone (a browser tab); it cannot be dismissed — the crew
 * install it to the home screen, then it opens standalone and this never renders.
 *
 * ONE flow per environment, and NEVER a fake button — every actionable control
 * is a real <button> with a bound handler:
 *  - iOS (any browser): Add-to-Home-Screen INSTRUCTIONS pointing at Safari's own
 *    Share button (iOS has no install API — there is no button that can trigger
 *    the install, so we don't pretend one exists). When the worker is NOT in
 *    Safari (Chrome/in-app on iOS), a banner tells them to open the page in
 *    Safari. A real "Copy link" button is ALWAYS present here, so a tap always
 *    does something — and it's the way a Chrome user carries the link to Safari.
 *  - Android/Chrome: a real one-tap Install button (beforeinstallprompt).
 *  - Non-iOS in-app webview: "open in your browser" + Copy link.
 *  - Anything else (desktop): generic "use the browser menu" + Copy link.
 *
 * iOS hardening (real-device tap bugs, not simulated):
 *  - touch-action: manipulation on every control → removes the 300 ms tap delay
 *    and kills double-tap-zoom stealing the tap.
 *  - the overlay is a fixed backdrop + an absolutely-positioned scroll layer
 *    (the reliable iOS pattern), content flows from the TOP with generous bottom
 *    padding so no control is ever trapped under Safari's floating toolbar.
 *  - a visible -webkit-tap-highlight-color so a real finger tap gives feedback.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { canPromptInstall, isInAppBrowser, isIos, isIosSafari, promptInstall } from '@/pwa';
import VButton from '@/Components/ui/VButton.vue';

// A native install prompt only ever exists off iOS — guard it so an install
// button can never show on an iPhone, whatever the UA quirks.
const canInstall = ref(canPromptInstall() && !isIos());
const onIos = ref(isIos());
const iosSafari = ref(isIosSafari());
const copied = ref(false);
const showLink = ref(false);
const linkUrl = `${window.location.origin}/worker`;

function refresh() {
    canInstall.value = canPromptInstall() && !isIos();
}

const mode = computed(() => {
    if (onIos.value) return 'ios';                 // Safari OR Chrome/in-app on iOS
    if (canInstall.value) return 'android';        // real one-tap install
    if (isInAppBrowser()) return 'open-browser';   // non-iOS in-app webview
    return 'generic';
});

async function install() {
    await promptInstall();
    refresh();
}

function logout() {
    router.post('/logout', {}, { replace: true });
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(linkUrl);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2500);
    } catch {
        // Clipboard API can be unavailable (older iOS / insecure context). Reveal
        // the URL inline as selectable text — NEVER a blocking window.prompt,
        // which freezes the page on some devices.
        showLink.value = true;
    }
}

onMounted(() => {
    window.addEventListener('pwa:installable', refresh);
    window.addEventListener('pwa:installed', refresh);
});
onUnmounted(() => {
    window.removeEventListener('pwa:installable', refresh);
    window.removeEventListener('pwa:installed', refresh);
});
</script>

<template>
    <div class="gate fixed inset-0 z-[100] bg-surface" role="dialog" aria-modal="true">
        <!-- Absolutely-positioned scroll layer (the reliable iOS overlay pattern).
             Content flows from the top; the big bottom padding keeps every control
             clear of Safari's floating toolbar. -->
        <div class="absolute inset-0 overflow-y-auto"
            style="padding-top: max(1.75rem, env(safe-area-inset-top)); padding-bottom: calc(env(safe-area-inset-bottom) + 6rem);">
            <div class="mx-auto flex w-full max-w-sm flex-col items-center px-6 text-center">

                <img src="/icons/icon-192.png" alt="AlphaRey"
                    class="h-[4.5rem] w-[4.5rem] rounded-[1.25rem] shadow-raised" width="72" height="72">

                <h1 class="mt-6 text-title font-semibold tracking-tight text-ink">{{ $t('worker.install_required_title') }}</h1>
                <p class="mt-2 text-sm leading-relaxed text-ink-soft">{{ $t('worker.install_required_body') }}</p>

                <!-- ============ iOS (Safari or Chrome/in-app) ============ -->
                <div v-if="mode === 'ios'" class="mt-6 w-full">
                    <!-- Not in Safari → the honest working path is: open in Safari. -->
                    <p v-if="!iosSafari"
                        class="mb-4 rounded-xl bg-status-warn-soft px-4 py-3 text-sm font-medium leading-relaxed text-status-warn">
                        {{ $t('worker.install_ios_chrome_banner') }}
                    </p>

                    <!-- The share glyph, shown big and unmistakable. -->
                    <div class="flex flex-col items-center">
                        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-accent-soft text-accent">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-7 w-7" aria-hidden="true">
                                <path d="M12 14V4" />
                                <path d="m8.5 7.5 3.5-3.5 3.5 3.5" />
                                <path d="M7 11H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-1" />
                            </svg>
                        </span>
                        <p class="mt-2 text-xs font-medium text-muted">{{ $t('worker.install_ios_share_caption') }}</p>
                    </div>

                    <!-- Numbered steps — informational, not tappable. -->
                    <ol class="mt-5 space-y-3 text-start">
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-[13px] font-semibold text-on-accent">1</span>
                            <span class="text-sm leading-relaxed text-ink">{{ $t('worker.install_ios_1') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-[13px] font-semibold text-on-accent">2</span>
                            <span class="text-sm leading-relaxed text-ink">
                                {{ $t('worker.install_ios_2_pre') }}
                                <span class="font-semibold text-ink">{{ $t('worker.install_ios_2_action') }}</span>
                            </span>
                        </li>
                    </ol>

                    <p class="mt-5 rounded-xl bg-surface-sunken px-4 py-3 text-xs leading-relaxed text-ink-soft">
                        {{ $t('worker.install_ios_note') }}
                    </p>

                    <!-- A real, always-present, always-working button: copy the link
                         (the way a Chrome user carries it to Safari). -->
                    <button type="button" @click="copyLink"
                        class="tap mt-4 flex w-full items-center justify-center gap-2 rounded-xl border border-line-strong bg-surface-raised px-4 py-3 text-sm font-semibold text-ink transition active:scale-[0.98] active:bg-surface-hover">
                        <svg v-if="!copied" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4" aria-hidden="true">
                            <rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 text-status-ok" aria-hidden="true">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        {{ copied ? $t('worker.install_copied') : $t('worker.install_copy_link') }}
                    </button>
                </div>

                <!-- ============ Android/Chrome: one real Install button ============ -->
                <div v-else-if="mode === 'android'" class="mt-7 w-full">
                    <VButton class="tap w-full rounded-xl text-base font-semibold" size="lg" @click="install">
                        {{ $t('worker.install') }}
                    </VButton>
                    <p class="mt-3 text-xs text-muted">{{ $t('worker.install_hint') }}</p>
                </div>

                <!-- ============ Non-iOS in-app webview ============ -->
                <div v-else-if="mode === 'open-browser'" class="mt-7 w-full">
                    <p class="rounded-xl bg-status-warn-soft px-4 py-3 text-sm leading-relaxed text-status-warn">
                        {{ $t('worker.install_open_safari') }}
                    </p>
                    <VButton variant="secondary" class="tap mt-3 w-full rounded-xl" @click="copyLink">
                        {{ copied ? $t('worker.install_copied') : $t('worker.install_copy_link') }}
                    </VButton>
                </div>

                <!-- ============ Generic (desktop / other) ============ -->
                <div v-else class="mt-7 w-full">
                    <p class="rounded-xl bg-surface-sunken px-4 py-3 text-sm leading-relaxed text-ink-soft">
                        {{ $t('worker.install_generic') }}
                    </p>
                    <VButton variant="secondary" class="tap mt-3 w-full rounded-xl" @click="copyLink">
                        {{ copied ? $t('worker.install_copied') : $t('worker.install_copy_link') }}
                    </VButton>
                </div>

                <!-- Clipboard-unavailable fallback: the link as selectable text,
                     so the worker can long-press → copy it by hand. -->
                <div v-if="showLink" class="mt-4 w-full">
                    <input :value="linkUrl" readonly onclick="this.select()"
                        class="tap w-full select-all rounded-xl border border-line-strong bg-surface-sunken px-3 py-2.5 text-center text-xs text-ink" />
                    <p class="mt-1.5 text-[11px] text-muted">{{ $t('worker.install_copy_manual') }}</p>
                </div>

                <!-- The only escape hatch under the gate: sign out. -->
                <button type="button"
                    class="tap mt-6 rounded-lg px-4 py-2 text-sm font-medium text-ink-soft transition active:scale-95 active:bg-surface-hover hover:text-ink"
                    @click="logout">
                    {{ $t('common.logout') }}
                </button>

                <p class="mt-3 text-xs text-muted">{{ $t('worker.powered_by') }}</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* iOS tap hardening: kill the 300 ms delay + double-tap-zoom, and give a real
   finger tap a visible highlight so the control obviously responds. */
.tap {
    touch-action: manipulation;
    -webkit-tap-highlight-color: rgba(212, 149, 106, 0.24);
}
</style>
