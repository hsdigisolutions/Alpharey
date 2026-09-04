<script setup>
/**
 * Full-screen BLOCKING install gate for the worker PWA. Shown whenever the app
 * is NOT running standalone (a browser tab); it cannot be dismissed — the crew
 * install it to the home screen, then it opens standalone and this never renders.
 *
 * ONE flow per environment, and NEVER a fake button:
 *  - iOS Safari      → step-by-step INSTRUCTIONS pointing at Safari's own Share
 *                      button (iOS has no install API — there is no button we can
 *                      offer, so we don't pretend to). The only real control here
 *                      is "Log out".
 *  - Android/Chrome  → a real one-tap Install button (beforeinstallprompt).
 *  - In-app webview  → cannot install anywhere; "open in Safari/Chrome" + Copy link.
 *  - Anything else   → generic "use the browser menu" + Copy link.
 *
 * Every actionable control is a real <button> with a bound handler. The two-card
 * "looks like a button" instruction layout that confused workers is gone.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { canPromptInstall, isInAppBrowser, isIos, isIosSafari, promptInstall } from '@/pwa';
import VButton from '@/Components/ui/VButton.vue';

// A native install prompt only ever exists off iOS — guard it so an install
// button can never show on an iPhone, whatever the UA quirks.
const canInstall = ref(canPromptInstall() && !isIos());
const copied = ref(false);

function refresh() {
    canInstall.value = canPromptInstall() && !isIos();
}

const mode = computed(() => {
    if (isIosSafari()) return 'ios-safari';               // the one iOS path (instructions)
    if (isIos() || isInAppBrowser()) return 'open-browser'; // cannot install here
    if (canInstall.value) return 'android';               // real one-tap install
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
    const url = `${window.location.origin}/worker`;
    try {
        await navigator.clipboard.writeText(url);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2500);
    } catch {
        window.prompt(url, url);
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
    <div class="fixed inset-0 z-[100] overflow-y-auto bg-surface"
        style="padding-top: max(2rem, env(safe-area-inset-top)); padding-bottom: max(2rem, env(safe-area-inset-bottom));"
        role="dialog" aria-modal="true">
        <div class="mx-auto flex min-h-full w-full max-w-sm flex-col items-center justify-center px-6 text-center">

            <img src="/icons/icon-192.png" alt="AlphaRey"
                class="h-[4.5rem] w-[4.5rem] rounded-[1.25rem] shadow-raised" width="72" height="72">

            <h1 class="mt-6 text-title font-semibold tracking-tight text-ink">{{ $t('worker.install_required_title') }}</h1>
            <p class="mt-2 text-sm leading-relaxed text-ink-soft">{{ $t('worker.install_required_body') }}</p>

            <!-- ============ iOS Safari: instructions only (no buttons) ============ -->
            <div v-if="mode === 'ios-safari'" class="mt-7 w-full">
                <!-- The share glyph, shown big and unmistakable, as the thing to look for. -->
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

                <!-- Numbered steps — clearly informational, not tappable. -->
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
            </div>

            <!-- ============ Android/Chrome: one real Install button ============ -->
            <div v-else-if="mode === 'android'" class="mt-7 w-full">
                <VButton class="w-full rounded-xl text-base font-semibold" size="lg" @click="install">
                    {{ $t('worker.install') }}
                </VButton>
                <p class="mt-3 text-xs text-muted">{{ $t('worker.install_hint') }}</p>
            </div>

            <!-- ============ In-app webview: open in the real browser ============ -->
            <div v-else-if="mode === 'open-browser'" class="mt-7 w-full">
                <p class="rounded-xl bg-status-warn-soft px-4 py-3 text-sm leading-relaxed text-status-warn">
                    {{ $t('worker.install_open_safari') }}
                </p>
                <VButton variant="secondary" class="mt-3 w-full rounded-xl" @click="copyLink">
                    {{ copied ? $t('worker.install_copied') : $t('worker.install_copy_link') }}
                </VButton>
            </div>

            <!-- ============ Generic (desktop / other) ============ -->
            <div v-else class="mt-7 w-full">
                <p class="rounded-xl bg-surface-sunken px-4 py-3 text-sm leading-relaxed text-ink-soft">
                    {{ $t('worker.install_generic') }}
                </p>
                <VButton variant="secondary" class="mt-3 w-full rounded-xl" @click="copyLink">
                    {{ copied ? $t('worker.install_copied') : $t('worker.install_copy_link') }}
                </VButton>
            </div>

            <!-- The only escape hatch under the gate: sign out. -->
            <button type="button"
                class="mt-8 rounded-lg px-3 py-1.5 text-sm font-medium text-ink-soft transition active:scale-95 active:bg-surface-hover hover:text-ink"
                @click="logout">
                {{ $t('common.logout') }}
            </button>

            <p class="mt-3 text-xs text-muted">{{ $t('worker.powered_by') }}</p>
        </div>
    </div>
</template>
