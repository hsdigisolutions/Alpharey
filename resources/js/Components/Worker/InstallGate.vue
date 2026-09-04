<script setup>
/**
 * Full-screen BLOCKING install gate for the worker PWA. Shown whenever the app
 * is NOT running standalone (a browser tab), and it cannot be dismissed — the
 * crew must install the app to the home screen before they can use it. Once
 * installed and launched from the home screen, isStandalone() is true and this
 * never renders (WorkerLayout stops mounting it).
 *
 * Per platform:
 *  - Android/Chrome (beforeinstallprompt fired) → a one-tap native Install button.
 *  - iOS Safari → the Share → "Add to Home Screen" steps (iOS has no install API).
 *  - iOS non-Safari / any in-app webview (WhatsApp, Gmail, Instagram…) → these
 *    cannot install; show "open in Safari" + a copy-link button.
 *  - Anything else → the generic "use the browser menu" fallback + copy link.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { canPromptInstall, isInAppBrowser, isIos, isIosSafari, promptInstall } from '@/pwa';
import VButton from '@/Components/ui/VButton.vue';

const canInstall = ref(canPromptInstall());
const copied = ref(false);

function refresh() {
    canInstall.value = canPromptInstall();
}

const mode = computed(() => {
    if (canInstall.value) return 'android';
    if (isIosSafari()) return 'ios-safari';
    if (isIos() || isInAppBrowser()) return 'open-browser';
    return 'generic';
});

async function install() {
    await promptInstall();
    refresh();
}

// The gate blocks the whole app EXCEPT logout — so a worker on the wrong
// device or account is never trapped and can always sign out.
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
        // Clipboard blocked (older webview): select-and-copy fallback via prompt.
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
    <div class="fixed inset-0 z-50 flex flex-col items-center justify-center overflow-y-auto bg-surface px-6 text-center"
        style="padding-top: max(1.5rem, env(safe-area-inset-top)); padding-bottom: max(1.5rem, env(safe-area-inset-bottom));"
        role="dialog" aria-modal="true">
        <div class="w-full max-w-sm">
            <img src="/icons/icon-192.png" alt="AlphaRey"
                class="mx-auto h-20 w-20 rounded-2xl shadow-card" width="80" height="80">

            <h1 class="mt-5 text-title font-semibold text-ink">{{ $t('worker.install_required_title') }}</h1>
            <p class="mt-2 text-sm text-ink-soft">{{ $t('worker.install_required_body') }}</p>

            <!-- Android / Chrome: one-tap native install -->
            <div v-if="mode === 'android'" class="mt-6">
                <VButton class="w-full rounded-xl text-base font-semibold" size="lg" @click="install">
                    {{ $t('worker.install') }}
                </VButton>
                <p class="mt-2 text-xs text-muted">{{ $t('worker.install_hint') }}</p>
            </div>

            <!-- iOS Safari: manual Add to Home Screen, with visual steps -->
            <div v-else-if="mode === 'ios-safari'" class="mt-6 space-y-3 text-left">
                <div class="flex items-start gap-3 rounded-lg border border-line bg-surface-raised p-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent text-sm font-semibold text-on-accent">1</span>
                    <p class="text-sm text-ink">
                        {{ $t('worker.install_ios_1') }}
                        <span class="inline-flex items-center align-middle text-accent" aria-hidden="true">
                            <!-- iOS Share glyph -->
                            <svg class="ml-1 h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 15V4" /><path d="m8 8 4-4 4 4" /><path d="M6 12H5a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-1" />
                            </svg>
                        </span>
                    </p>
                </div>
                <div class="flex items-start gap-3 rounded-lg border border-line bg-surface-raised p-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent text-sm font-semibold text-on-accent">2</span>
                    <p class="text-sm text-ink">{{ $t('worker.install_ios_2') }}</p>
                </div>
            </div>

            <!-- iOS non-Safari / in-app webview: must open in the real browser -->
            <div v-else-if="mode === 'open-browser'" class="mt-6">
                <p class="rounded-lg bg-status-warn-soft px-4 py-3 text-sm text-status-warn">
                    {{ $t('worker.install_open_safari') }}
                </p>
                <VButton variant="secondary" class="mt-3 w-full" @click="copyLink">
                    {{ copied ? $t('worker.install_copied') : $t('worker.install_copy_link') }}
                </VButton>
            </div>

            <!-- Anything else (desktop / other browser): generic guidance -->
            <div v-else class="mt-6">
                <p class="rounded-lg bg-surface-raised px-4 py-3 text-sm text-ink-soft">
                    {{ $t('worker.install_generic') }}
                </p>
                <VButton variant="secondary" class="mt-3 w-full" @click="copyLink">
                    {{ copied ? $t('worker.install_copied') : $t('worker.install_copy_link') }}
                </VButton>
            </div>

            <!-- Only escape hatch under the gate: sign out (switch account / recover). -->
            <button type="button"
                class="mt-6 text-sm font-medium text-ink-soft underline-offset-2 transition hover:text-ink hover:underline active:scale-95"
                @click="logout">
                {{ $t('common.logout') }}
            </button>

            <p class="mt-4 text-xs text-muted">{{ $t('worker.powered_by') }}</p>
        </div>
    </div>
</template>
