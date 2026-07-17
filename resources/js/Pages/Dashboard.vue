<script setup>
/**
 * Screen 03 placeholder — the real dashboard (KPIs, charts, panels) is
 * built in Phase 8. Exercises the shell, tenancy context, and bilingual
 * navigation end to end, now on the D1–D3 design system.
 */
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import VKpiCard from '@/Components/ui/VKpiCard.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';

const placeholderKpis = [
    { k: 'nav.employees', icon: 'employees' },
    { k: 'nav.projects', icon: 'projects' },
    { k: 'nav.invoices', icon: 'invoices' },
    { k: 'nav.attendance', icon: 'attendance' },
];
</script>

<template>
    <Head :title="$t('nav.dashboard')" />

    <AppLayout>
        <VPageHeader k="nav.dashboard" />
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <VKpiCard v-for="kpi in placeholderKpis" :key="kpi.k" :k="kpi.k" :icon="kpi.icon" value="—" />
        </div>

        <p class="mt-8 text-sm text-muted">
            <Bilingual k="dashboard.placeholder" />
        </p>
    </AppLayout>
</template>
