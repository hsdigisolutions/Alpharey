<script setup>
/**
 * Worker PWA — home screen (Phase A shell).
 *
 * Mobile-first and deliberately bare: a worker holds a phone in one hand on a
 * building site, so this is one column, large touch targets, no sidebar and no
 * navigation to anywhere they cannot go.
 *
 * The check-in/out control, the month calendar and the figures arrive in
 * Phases C and D — this screen currently proves the identity resolved.
 */
import { Head } from '@inertiajs/vue3';

defineProps({
    worker: { type: Object, required: true },
});
</script>

<template>
    <Head :title="$t('worker.title')" />

    <div class="min-h-screen bg-surface px-4 py-6">
        <div class="mx-auto w-full max-w-md space-y-5">
            <header class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent text-sm font-bold text-on-accent">AR</span>
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-ink">{{ worker.name }}</p>
                    <p class="truncate text-sm text-ink-soft">
                        {{ worker.code }}<template v-if="worker.company"> · {{ worker.company }}</template>
                    </p>
                </div>
            </header>

            <div class="rounded-lg border border-line bg-surface-raised p-5 text-center shadow-card">
                <p class="text-sm text-ink-soft">
                    <Bilingual k="worker.coming_soon" class="items-center" />
                </p>
            </div>
        </div>
    </div>
</template>
