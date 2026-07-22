<script setup>
/**
 * Worker PWA — home screen.
 *
 * Phase B ships the installable shell and the resolved identity. The check-in
 * / check-out control with GPS and selfie (Phase C), the month calendar and
 * the figures (Phase D) drop into this single column.
 */
import { Head } from '@inertiajs/vue3';
import WorkerLayout from '@/Layouts/WorkerLayout.vue';

defineProps({
    worker: { type: Object, required: true },
});
</script>

<template>
    <Head :title="$t('worker.title')" />

    <WorkerLayout :worker="worker">
        <div class="rounded-lg border border-line bg-surface-raised p-6 text-center shadow-card">
            <p class="text-sm text-ink-soft">
                <Bilingual k="worker.coming_soon" class="items-center" />
            </p>
        </div>
    </WorkerLayout>
</template>
