<script setup>
/**
 * The geolocation + selfie privacy consent screen (GDPR art. 7 & 13), shown
 * before a worker's first punch and again whenever the notice version changes.
 *
 * Legal model:
 *  - Attendance time record = a legal obligation (RD-ley 8/2019), mandatory —
 *    the worker ACKNOWLEDGES being informed (must tick to proceed).
 *  - GPS and the selfie are OPTIONAL, each its own consent checkbox — the app
 *    works without them, so the choice is genuinely free.
 *
 * The Accept button stays disabled until the worker has (a) scrolled the whole
 * notice and (b) ticked the mandatory acknowledgement. The server records the
 * exact text, IP, user-agent, timestamp, version and language as evidence and
 * refuses a punch without an active consent — this screen is not the only gate.
 */
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';

const scrolledToBottom = ref(false);

// This screen covers the whole viewport before the layout's language switch is
// reachable, so it carries its own ES · EN · UR toggle — a worker who doesn't
// read Spanish must be able to read the notice before consenting. Switching
// re-renders the $t notice (UR falls back to English for the legal block).
const page = usePage();
const primary = computed(() => page.props.locale?.primary ?? 'es');
function setLang(lang) {
    if (lang !== primary.value) {
        router.post('/locale', { locale: lang }, { preserveScroll: true });
    }
}

const form = useForm({
    consent_attendance: false, // mandatory acknowledgement
    consent_gps: true,         // optional (default on, worker can untick)
    consent_photo: true,       // optional
});

function onScroll(e) {
    const el = e.target;
    if (el.scrollTop + el.clientHeight >= el.scrollHeight - 24) {
        scrolledToBottom.value = true;
    }
}

function accept() {
    form.post('/worker/privacy-ack', { preserveScroll: true });
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex flex-col bg-surface"
        style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom)">
        <!-- Scrollable notice body -->
        <div class="flex-1 overflow-y-auto px-5 py-6" @scroll="onScroll">
            <div class="mx-auto w-full max-w-md">
                <!-- Language switch (this screen precedes the layout's toggle) -->
                <div class="mb-4 flex justify-end gap-1">
                    <button v-for="l in ['es', 'en', 'ur']" :key="l" type="button"
                        class="rounded-md px-2.5 py-1 text-xs font-semibold uppercase transition-colors"
                        :class="primary === l ? 'bg-accent text-on-accent' : 'bg-surface-sunken text-ink-soft hover:text-ink'"
                        @click="setLang(l)">{{ l }}</button>
                </div>
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

                <section class="mb-4 rounded-lg border border-line bg-surface-raised p-4 shadow-card">
                    <h2 class="mb-2 text-sm font-semibold text-ink">{{ $t('worker.privacy.data_title') }}</h2>
                    <ul class="space-y-1.5 text-sm text-ink-soft">
                        <li class="flex gap-2"><span class="text-accent">•</span>{{ $t('worker.privacy.data_location') }}</li>
                        <li class="flex gap-2"><span class="text-accent">•</span>{{ $t('worker.privacy.data_selfie') }}</li>
                        <li class="flex gap-2"><span class="text-accent">•</span>{{ $t('worker.privacy.data_time') }}</li>
                    </ul>
                </section>

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

                <!-- Consent checkboxes (separate purposes, GDPR art. 7) -->
                <div class="mt-5 space-y-3">
                    <label class="flex items-start gap-3 rounded-lg border border-line-strong bg-surface-raised p-3">
                        <input v-model="form.consent_attendance" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-accent" />
                        <span class="text-sm text-ink">{{ $t('worker.privacy.consent_attendance') }}</span>
                    </label>
                    <label class="flex items-start gap-3 rounded-lg border border-line bg-surface-raised p-3">
                        <input v-model="form.consent_gps" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-accent" />
                        <span class="text-sm text-ink-soft">{{ $t('worker.privacy.consent_gps') }}</span>
                    </label>
                    <label class="flex items-start gap-3 rounded-lg border border-line bg-surface-raised p-3">
                        <input v-model="form.consent_photo" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-accent" />
                        <span class="text-sm text-ink-soft">{{ $t('worker.privacy.consent_photo') }}</span>
                    </label>
                </div>

                <p v-if="!scrolledToBottom" class="mt-4 text-center text-xs text-status-warn">{{ $t('worker.privacy.scroll_hint') }}</p>
            </div>
        </div>

        <!-- Sticky accept bar -->
        <div class="border-t border-line bg-surface-raised px-5 py-4 shadow-overlay"
            style="padding-bottom: calc(1rem + env(safe-area-inset-bottom))">
            <div class="mx-auto w-full max-w-md">
                <button type="button"
                    class="w-full rounded-md bg-accent px-5 py-4 text-base font-medium text-on-accent shadow-card transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!scrolledToBottom || !form.consent_attendance || form.processing"
                    @click="accept">
                    {{ $t('worker.privacy.ack') }}
                </button>
            </div>
        </div>
    </div>
</template>
