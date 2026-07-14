<script setup>
/**
 * Screen 02 — Welcome / Company Selector (Super Admin only).
 * Employee/project/compliance/deployment figures render as "—" until
 * their phases land (2–5); the structure matches the final spec.
 */
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VStatusDot from '@/Components/ui/VStatusDot.vue';

const props = defineProps({
    companies: { type: Array, required: true },
    stats: { type: Object, required: true },
});

const page = usePage();

const greetingKey = computed(() => {
    const hour = Number(
        new Intl.DateTimeFormat('es-ES', { hour: 'numeric', hourCycle: 'h23', timeZone: 'Europe/Madrid' })
            .format(new Date()),
    );
    if (hour < 14) return 'welcome.greeting_morning';
    if (hour < 21) return 'welcome.greeting_afternoon';
    return 'welcome.greeting_evening';
});

const madridNow = computed(() =>
    new Intl.DateTimeFormat(page.props.locale.primary === 'es' ? 'es-ES' : 'en-GB', {
        dateStyle: 'full',
        timeStyle: 'short',
        timeZone: 'Europe/Madrid',
    }).format(new Date()),
);

function enter(company) {
    router.post(`/welcome/${company.id}/select`);
}

const statCards = [
    { key: 'total_employees', labelKey: 'welcome.stats_employees' },
    { key: 'active_projects', labelKey: 'welcome.stats_projects' },
    { key: 'docs_expiring', labelKey: 'welcome.stats_docs' },
    { key: 'invoices_pending', labelKey: 'welcome.stats_invoices' },
    { key: 'active_deployments', labelKey: 'welcome.stats_deployments' },
];
</script>

<template>
    <Head title="Bienvenido" />

    <AppLayout>
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight">
                <Bilingual :k="greetingKey" inline />, {{ page.props.auth.user?.name }}
            </h1>
            <p class="tabular-nums mt-1 text-sm text-muted">{{ madridNow }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div v-for="company in props.companies" :key="company.id"
                class="flex flex-col rounded-xl border border-line bg-surface-raised p-5 shadow-card">
                <div class="flex items-start gap-3">
                    <VAvatar :name="company.name" size="lg" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[17px] font-semibold">{{ company.name }}</p>
                        <p class="truncate text-sm text-ink-soft">{{ company.province ?? '—' }}</p>
                        <p class="tabular-nums text-xs text-muted">{{ company.cif ?? '—' }}</p>
                    </div>
                    <VStatusDot :status="company.compliance ?? 'neutral'" />
                </div>

                <dl class="tabular-nums mt-4 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-md bg-surface-sunken/70 p-2">
                        <dt><Bilingual k="welcome.employees" class="items-center text-[10px] text-muted" /></dt>
                        <dd class="mt-0.5 text-sm font-semibold">{{ company.employees_count ?? '—' }}</dd>
                    </div>
                    <div class="rounded-md bg-surface-sunken/70 p-2">
                        <dt><Bilingual k="welcome.projects" class="items-center text-[10px] text-muted" /></dt>
                        <dd class="mt-0.5 text-sm font-semibold">{{ company.projects_count ?? '—' }}</dd>
                    </div>
                    <div class="rounded-md bg-surface-sunken/70 p-2">
                        <dt><Bilingual k="welcome.deployed" class="items-center text-[10px] text-muted" /></dt>
                        <dd class="mt-0.5 text-sm font-semibold">{{ company.deployed_count ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <VBadge :status="company.status === 'active' ? 'ok' : 'neutral'">
                        <Bilingual :k="company.status === 'active' ? 'companies.active' : 'companies.inactive'" inline />
                    </VBadge>
                    <VButton size="sm" @click="enter(company)">
                        <Bilingual k="welcome.enter" inline />
                    </VButton>
                </div>
            </div>
        </div>

        <!-- Group-wide stats bar -->
        <div class="tabular-nums mt-8 grid grid-cols-2 gap-3 rounded-lg border border-line bg-surface-raised p-4 shadow-card sm:grid-cols-5">
            <div v-for="stat in statCards" :key="stat.key" class="text-center">
                <p class="text-lg font-semibold">{{ props.stats[stat.key] ?? '—' }}</p>
                <Bilingual :k="stat.labelKey" class="items-center text-[11px] text-muted" />
            </div>
        </div>
    </AppLayout>
</template>
