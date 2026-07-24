<script setup>
/**
 * The geolocation + selfie privacy notice, shown once (per notice version)
 * before a worker's first punch.
 *
 * This is an INFORMATION screen, not a consent form: Spanish law does not base
 * worker monitoring on consent, so the button acknowledges having READ the
 * notice — it does not "agree" to anything (see docs/GDPR_WORKER_NOTICE.md).
 * It fills the screen and blocks check-in until acknowledged; the server
 * refuses a punch without the acknowledgement too, so this is not the only gate.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import VButton from '@/Components/ui/VButton.vue';

const busy = ref(false);

function acknowledge() {
    busy.value = true;
    router.post('/worker/privacy-ack', {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex flex-col bg-surface"
        style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom)">
        <!-- Scrollable notice body -->
        <div class="flex-1 overflow-y-auto px-5 py-6">
            <div class="mx-auto w-full max-w-md">
                <div class="mb-5 flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                        </svg>
                    </span>
                    <div>
                        <h1 class="text-lg font-semibold leading-tight text-ink">{{ $t('worker.privacy.title') }}</h1>
                        <p class="text-xs text-ink-soft">{{ $t('worker.privacy.subtitle') }}</p>
                    </div>
                </div>

                <p class="mb-4 text-sm text-ink-soft">{{ $t('worker.privacy.intro') }}</p>

                <!-- What is collected -->
                <section class="mb-4 rounded-lg border border-line bg-surface-raised p-4 shadow-card">
                    <h2 class="mb-2 text-sm font-semibold text-ink">{{ $t('worker.privacy.data_title') }}</h2>
                    <ul class="space-y-1.5 text-sm text-ink-soft">
                        <li class="flex gap-2"><span class="text-accent">•</span>{{ $t('worker.privacy.data_location') }}</li>
                        <li class="flex gap-2"><span class="text-accent">•</span>{{ $t('worker.privacy.data_selfie') }}</li>
                        <li class="flex gap-2"><span class="text-accent">•</span>{{ $t('worker.privacy.data_time') }}</li>
                    </ul>
                </section>

                <!-- Why / who / retention / rights -->
                <div class="space-y-4">
                    <div>
                        <h2 class="mb-1 text-sm font-semibold text-ink">{{ $t('worker.privacy.why_title') }}</h2>
                        <p class="text-sm text-ink-soft">{{ $t('worker.privacy.why_body') }}</p>
                    </div>
                    <div>
                        <h2 class="mb-1 text-sm font-semibold text-ink">{{ $t('worker.privacy.who_title') }}</h2>
                        <p class="text-sm text-ink-soft">{{ $t('worker.privacy.who_body') }}</p>
                    </div>
                    <div>
                        <h2 class="mb-1 text-sm font-semibold text-ink">{{ $t('worker.privacy.retention_title') }}</h2>
                        <p class="text-sm text-ink-soft">{{ $t('worker.privacy.retention_body') }}</p>
                    </div>
                    <div>
                        <h2 class="mb-1 text-sm font-semibold text-ink">{{ $t('worker.privacy.rights_title') }}</h2>
                        <p class="text-sm text-ink-soft">{{ $t('worker.privacy.rights_body') }}</p>
                    </div>
                </div>

                <p class="mt-4 rounded-md bg-surface-sunken px-3 py-2 text-xs text-ink-soft">
                    {{ $t('worker.privacy.gps_note') }}
                </p>
            </div>
        </div>

        <!-- Sticky acknowledge bar -->
        <div class="border-t border-line bg-surface-raised px-5 py-4 shadow-overlay"
            style="padding-bottom: calc(1rem + env(safe-area-inset-bottom))">
            <div class="mx-auto w-full max-w-md">
                <VButton class="w-full" size="lg" :loading="busy" @click="acknowledge">
                    {{ $t('worker.privacy.ack') }}
                </VButton>
            </div>
        </div>
    </div>
</template>
