<script setup>
/**
 * Screen 03 placeholder — the real dashboard (KPIs, charts, panels) is
 * built in Phase 8. This page exists so the authenticated shell, tenancy
 * context, and bilingual navigation are exercised end to end from Phase 0.
 */
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const placeholderKpis = ['employees', 'projects', 'invoices', 'attendance'];
</script>

<template>
    <Head title="Panel" />

    <AppLayout title-key="dashboard.title">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div v-for="key in placeholderKpis" :key="key"
                class="rounded-2xl border border-line bg-surface-raised p-4">
                <Bilingual :k="`nav.${key}`" class="text-xs font-medium text-ink-soft" />
                <p class="mt-2 text-2xl font-semibold text-muted">—</p>
            </div>
        </div>

        <p class="mt-8 text-sm text-muted">
            <Bilingual k="dashboard.placeholder" />
        </p>
    </AppLayout>
</template>
