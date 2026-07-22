<script setup>
/**
 * The Worker PWA shell — deliberately NOT AppLayout.
 *
 * A worker holds a phone in one hand on a building site, often in gloves and
 * sunlight. So: one column, large touch targets, no sidebar, no global search,
 * no module navigation — there is nowhere else for them to go. The only
 * chrome is who they are and a way out.
 *
 * Padding uses env(safe-area-inset-*) so the installed app clears an iPhone
 * notch and home indicator instead of hiding content under them.
 */
import { onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { canPromptInstall, isIos, isStandalone, promptInstall } from '@/pwa';
import VButton from '@/Components/ui/VButton.vue';

defineProps({
    worker: { type: Object, required: true },
});

const showInstall = ref(false);
const showIosHint = ref(false);

function refreshInstallState() {
    if (isStandalone()) {
        showInstall.value = false;
        showIosHint.value = false;

        return;
    }

    // Android/Chrome gives us a real prompt; iOS has no install API at all, so
    // the only honest thing to offer there is the manual instruction.
    showInstall.value = canPromptInstall();
    showIosHint.value = isIos();
}

function onInstallable() {
    refreshInstallState();
}

onMounted(() => {
    refreshInstallState();
    window.addEventListener('pwa:installable', onInstallable);
    window.addEventListener('pwa:installed', refreshInstallState);
});

onUnmounted(() => {
    window.removeEventListener('pwa:installable', onInstallable);
    window.removeEventListener('pwa:installed', refreshInstallState);
});

async function install() {
    await promptInstall();
    refreshInstallState();
}

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-surface"
        style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom)">
        <div class="mx-auto w-full max-w-md px-4 py-5">
            <header class="mb-5 flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent text-sm font-bold text-on-accent">AR</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-base font-semibold leading-tight text-ink">{{ worker.name }}</p>
                    <p class="truncate text-xs text-ink-soft">
                        {{ worker.code }}<template v-if="worker.company"> · {{ worker.company }}</template>
                    </p>
                </div>
                <!-- 44px touch target (design skill mobile rule) -->
                <button type="button"
                    class="flex h-11 w-11 items-center justify-center rounded-md text-ink-soft hover:bg-surface-hover"
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

            <!-- Install: a real prompt on Android, the manual route on iOS -->
            <div v-if="showInstall" class="mb-4 rounded-lg border border-line bg-surface-raised p-3 shadow-card">
                <p class="mb-2 text-sm text-ink-soft"><Bilingual k="worker.install_hint" /></p>
                <VButton class="w-full" @click="install">
                    <Bilingual k="worker.install" inline />
                </VButton>
            </div>

            <div v-else-if="showIosHint"
                class="mb-4 rounded-lg border border-line bg-surface-raised p-3 text-xs text-ink-soft shadow-card">
                <Bilingual k="worker.install_ios" />
            </div>

            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
