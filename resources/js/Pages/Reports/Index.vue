<script setup>
/**
 * Screen 14 — Reports. One page, a persistent filter bar (module + date
 * range), and the selected module's figures + tables. Export PDF/Excel of
 * the exact filtered view. Everything is computed server-side by
 * ReportService; this page renders whatever shape it returns.
 */
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VCard from '@/Components/ui/VCard.vue';
import VButton from '@/Components/ui/VButton.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { t } from '@/translate';
import VSelect from '@/Components/ui/VSelect.vue';

const props = defineProps({
    module: { type: String, required: true },
    filters: { type: Object, required: true },
    modules: { type: Array, required: true },
    report: { type: Object, default: null },
    blocked: { type: Boolean, default: false },
});

const form = reactive({
    module: props.module,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function apply() {
    router.get(route('reports.index'), {
        module: form.module,
        from: form.from || undefined,
        to: form.to || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function exportUrl(kind) {
    const params = new URLSearchParams({ module: form.module });
    if (form.from) params.set('from', form.from);
    if (form.to) params.set('to', form.to);

    return `${route(`reports.export.${kind}`)}?${params.toString()}`;
}

function num(value) {
    if (typeof value !== 'number') return value;
    return Number.isInteger(value)
        ? value.toLocaleString('es-ES')
        : value.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Figures → labelled stat tiles.
const figures = computed(() => Object.entries(props.report?.figures ?? {}).map(([key, value]) => ({
    key,
    label: t(`reports.${key}`) ?? key,
    value: num(value),
})));

// The module's primary detail table, if any (key + shape vary by module).
const table = computed(() => {
    if (!props.report) return null;
    const r = props.report;
    const pick = {
        attendance: r.by_employee, payroll: r.by_employee, projects: r.hours_per_project,
        commission: r.rows, timesheet: r.rows, deployments: r.rows, financial: r.unpaid_invoices,
    }[props.module];

    return Array.isArray(pick) && pick.length ? pick : null;
});

const tableColumns = computed(() => (table.value ? Object.keys(table.value[0]) : []));
</script>

<template>
    <Head :title="$t('reports.title')" />

    <AppLayout>
        <VPageHeader k="reports.title" />

        <!-- Persistent filter bar -->
        <VCard class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-ink-soft">{{ $t('reports.module') }}</span>
                    <VSelect v-model="form.module" @update:model-value="apply">
                        <option v-for="m in modules" :key="m" :value="m">{{ t(`reports.mod_${m}`) }}</option>
                    </VSelect>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-ink-soft">{{ $t('reports.date_from') }}</span>
                    <input v-model="form.from" type="date" @change="apply"
                        class="rounded-md border border-line-strong bg-surface-sunken px-3 py-1.5 text-sm text-ink focus:border-accent focus:outline-none" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-ink-soft">{{ $t('reports.date_to') }}</span>
                    <input v-model="form.to" type="date" @change="apply"
                        class="rounded-md border border-line-strong bg-surface-sunken px-3 py-1.5 text-sm text-ink focus:border-accent focus:outline-none" />
                </label>
                <div class="ms-auto flex gap-2">
                    <a :href="exportUrl('pdf')">
                        <VButton variant="secondary"><AppIcon name="documents" class="me-1.5 h-4 w-4" />{{ $t('reports.export_pdf') }}</VButton>
                    </a>
                    <a :href="exportUrl('excel')">
                        <VButton variant="secondary"><AppIcon name="documents" class="me-1.5 h-4 w-4" />{{ $t('reports.export_excel') }}</VButton>
                    </a>
                </div>
            </div>
        </VCard>

        <VEmptyState v-if="blocked" title-key="reports.blocked" message-key="reports.blocked" />

        <template v-else-if="report">
            <!-- Figures -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div v-for="fig in figures" :key="fig.key" class="rounded-lg border border-line bg-surface-raised p-4 shadow-card">
                    <p class="text-xs font-medium text-ink-soft">{{ fig.label }}</p>
                    <p class="tabular-nums mt-1 text-xl font-semibold text-ink">{{ fig.value }}</p>
                </div>
            </div>

            <!-- Primary detail table -->
            <VCard v-if="table" class="mt-6">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-start text-xs uppercase text-muted">
                                <th v-for="col in tableColumns" :key="col" class="px-2 py-2 text-start font-medium">
                                    {{ t(`reports.${col}`) ?? col.replace('_', ' ') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in table" :key="i" class="border-b border-line">
                                <td v-for="col in tableColumns" :key="col" class="tabular-nums px-2 py-2 text-ink">
                                    <template v-if="typeof row[col] === 'boolean'">{{ row[col] ? '✓' : '—' }}</template>
                                    <template v-else>{{ typeof row[col] === 'number' ? num(row[col]) : (row[col] ?? '—') }}</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </VCard>

            <VEmptyState v-else-if="figures.length === 0" title-key="reports.no_data" message-key="reports.no_data" />
        </template>
    </AppLayout>
</template>
