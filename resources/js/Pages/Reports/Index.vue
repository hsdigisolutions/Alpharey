<script setup>
/**
 * Screen 14 — Reports. One page, a persistent filter bar (module + date
 * range), and the selected module's figures + tables. Export PDF/Excel of
 * the exact filtered view. Everything is computed server-side by
 * ReportService; this page renders whatever shape it returns.
 */
import { computed, reactive, ref } from 'vue';
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
    projectOptions: { type: Array, default: () => [] },
    clientOptions: { type: Array, default: () => [] },
});

const form = reactive({
    module: props.module,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    project_id: props.filters.project_id ?? '',
    client_id: props.filters.client_id ?? '',
});

const isProfit = computed(() => form.module === 'profitability');

function apply() {
    router.get(route('reports.index'), {
        module: form.module,
        from: form.from || undefined,
        to: form.to || undefined,
        project_id: isProfit.value && form.project_id ? form.project_id : undefined,
        client_id: isProfit.value && form.client_id ? form.client_id : undefined,
    }, { preserveState: true, preserveScroll: true });
}

/* ── Date presets (quick filtering) ── */
function iso(d) { return d.toISOString().slice(0, 10); }
function todayIso() { return iso(new Date()); }
function firstOfMonth(offset = 0) { const d = new Date(); d.setMonth(d.getMonth() + offset, 1); return iso(d); }
function lastOfMonth(offset = 0) { const d = new Date(); d.setMonth(d.getMonth() + offset + 1, 0); return iso(d); }
function firstOfYear() { return `${new Date().getFullYear()}-01-01`; }

function computeReportPreset() {
    if (form.from === firstOfMonth(0) && form.to === todayIso()) return 'this_month';
    if (form.from === firstOfMonth(-1) && form.to === lastOfMonth(-1)) return 'last_month';
    if ((form.from === firstOfYear() || !form.from) && (form.to === todayIso() || !form.to)) return 'this_year';
    return 'custom';
}
const reportPreset = ref(computeReportPreset());
function setReportPreset(p) {
    reportPreset.value = p;
    if (p === 'this_month') { form.from = firstOfMonth(0); form.to = todayIso(); }
    else if (p === 'last_month') { form.from = firstOfMonth(-1); form.to = lastOfMonth(-1); }
    else if (p === 'this_year') { form.from = firstOfYear(); form.to = todayIso(); }
    if (p !== 'custom') apply();
}

// Humanised header for the generic detail table (raw DB keys → Title Case).
function columnLabel(col) {
    return col.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

// Reset the drill-down when switching module or client (a stale project id
// would keep showing one project's breakdown under the wrong filter).
function changeModule() {
    form.project_id = '';
    form.client_id = '';
    apply();
}

function openProject(id) {
    form.project_id = id;
    apply();
}

function exportUrl(kind) {
    const params = new URLSearchParams({ module: form.module });
    if (form.from) params.set('from', form.from);
    if (form.to) params.set('to', form.to);
    if (isProfit.value && form.project_id) params.set('project_id', form.project_id);
    if (isProfit.value && form.client_id) params.set('client_id', form.client_id);

    return `${route(`reports.export.${kind}`)}?${params.toString()}`;
}

// Margin colour for the profitability table: > 15 % green · 5–15 % amber ·
// < 5 % / negative / a cost with no revenue red.
function marginClass(row) {
    if (row.margin === null || row.margin === undefined) {
        return (Number(row.coste_mo) + Number(row.gastos)) > 0 ? 'text-status-danger' : 'text-ink-soft';
    }
    if (row.margin > 15) return 'text-status-ok';
    if (row.margin >= 5) return 'text-status-warn';
    return 'text-status-danger';
}

function eur(value) {
    if (value === null || value === undefined) return '—';
    return `${Number(value).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
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
                    <VSelect v-model="form.module" @update:model-value="changeModule">
                        <option v-for="m in modules" :key="m" :value="m">{{ t(`reports.mod_${m}`) }}</option>
                    </VSelect>
                </label>
                <div class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-ink-soft">{{ $t('reports.period') }}</span>
                    <div class="inline-flex rounded-md border border-line-strong bg-surface-raised p-0.5">
                        <button v-for="p in ['this_month', 'last_month', 'this_year', 'custom']" :key="p" type="button"
                            class="rounded px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="reportPreset === p ? 'bg-accent text-on-accent' : 'text-ink-soft hover:text-ink'"
                            @click="setReportPreset(p)">{{ $t(`reports.preset_${p}`) }}</button>
                    </div>
                </div>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-ink-soft">{{ $t('reports.date_from') }}</span>
                    <input v-model="form.from" type="date" @change="() => { reportPreset = 'custom'; apply(); }"
                        class="rounded-md border border-line-strong bg-surface-sunken px-3 py-1.5 text-sm text-ink focus:border-accent focus:outline-none" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-ink-soft">{{ $t('reports.date_to') }}</span>
                    <input v-model="form.to" type="date" @change="() => { reportPreset = 'custom'; apply(); }"
                        class="rounded-md border border-line-strong bg-surface-sunken px-3 py-1.5 text-sm text-ink focus:border-accent focus:outline-none" />
                </label>
                <!-- Profitability-only: client + project filters -->
                <label v-if="isProfit" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-ink-soft">{{ $t('profitability.filter_client') }}</span>
                    <VSelect v-model="form.client_id" @update:model-value="apply">
                        <option value="">{{ $t('profitability.all_clients') }}</option>
                        <option v-for="c in clientOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </VSelect>
                </label>
                <label v-if="isProfit" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-ink-soft">{{ $t('profitability.filter_project') }}</span>
                    <VSelect v-model="form.project_id" @update:model-value="apply">
                        <option value="">{{ $t('profitability.all_projects') }}</option>
                        <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
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

            <!-- Profitability: colour-coded P&L table + optional drill-down -->
            <template v-if="isProfit">
                <VCard class="mt-6" :padded="false">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-line text-xs uppercase text-muted">
                                    <th class="px-3 py-2 text-start font-medium">{{ $t('profitability.col_project') }}</th>
                                    <th class="px-3 py-2 text-start font-medium">{{ $t('profitability.col_client') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ $t('profitability.col_hours') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ $t('profitability.col_revenue') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ $t('profitability.col_labour') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ $t('profitability.col_expenses') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ $t('profitability.col_profit') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ $t('profitability.col_margin') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in (report.rows ?? [])" :key="i"
                                    class="cursor-pointer border-b border-line hover:bg-surface-hover"
                                    @click="openProject(row.project_id)">
                                    <td class="px-3 py-2 font-medium text-ink">{{ row.project }}</td>
                                    <td class="px-3 py-2 text-ink-soft">{{ row.client }}</td>
                                    <td class="tabular-nums px-3 py-2 text-end">{{ row.hours }}</td>
                                    <td class="tabular-nums px-3 py-2 text-end">{{ eur(row.revenue) }}</td>
                                    <td class="tabular-nums px-3 py-2 text-end">{{ eur(row.coste_mo) }}</td>
                                    <td class="tabular-nums px-3 py-2 text-end">{{ eur(row.gastos) }}</td>
                                    <td class="tabular-nums px-3 py-2 text-end font-medium" :class="marginClass(row)">{{ eur(row.profit) }}</td>
                                    <td class="tabular-nums px-3 py-2 text-end font-medium" :class="marginClass(row)">
                                        {{ row.margin !== null ? `${row.margin.toLocaleString('es-ES')} %` : '—' }}
                                    </td>
                                </tr>
                                <tr v-if="!(report.rows ?? []).length">
                                    <td colspan="8" class="px-3 py-6 text-center text-sm text-muted">{{ $t('profitability.no_data') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </VCard>

                <!-- Drill-down: day + month breakdown for the selected project -->
                <template v-if="report.selected">
                    <button type="button" class="mt-4 text-sm text-accent hover:underline" @click="openProject('')">
                        ← {{ $t('profitability.back_to_all') }}
                    </button>

                    <div class="mt-3 grid gap-6 lg:grid-cols-2">
                        <VCard :title="`${$t('profitability.month_breakdown')} · ${report.selected.project}`">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-line text-xs uppercase text-muted">
                                            <th class="px-2 py-2 text-start font-medium">{{ $t('profitability.col_month') }}</th>
                                            <th class="px-2 py-2 text-end font-medium">{{ $t('profitability.col_hours') }}</th>
                                            <th class="px-2 py-2 text-end font-medium">{{ $t('profitability.col_revenue') }}</th>
                                            <th class="px-2 py-2 text-end font-medium">{{ $t('profitability.col_day_cost') }}</th>
                                            <th class="px-2 py-2 text-end font-medium">{{ $t('profitability.col_profit') }}</th>
                                            <th class="px-2 py-2 text-end font-medium">{{ $t('profitability.col_margin') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(m, i) in report.selected.month_breakdown" :key="i" class="border-b border-line">
                                            <td class="px-2 py-1.5">{{ m.month }}</td>
                                            <td class="tabular-nums px-2 py-1.5 text-end">{{ m.hours }}</td>
                                            <td class="tabular-nums px-2 py-1.5 text-end">{{ m.revenue !== null ? eur(m.revenue) : '—' }}</td>
                                            <td class="tabular-nums px-2 py-1.5 text-end">{{ eur(m.cost) }}</td>
                                            <td class="tabular-nums px-2 py-1.5 text-end">{{ m.profit !== null ? eur(m.profit) : '—' }}</td>
                                            <td class="tabular-nums px-2 py-1.5 text-end">{{ m.margin !== null ? `${m.margin} %` : '—' }}</td>
                                        </tr>
                                        <tr v-if="!report.selected.month_breakdown.length"><td colspan="6" class="px-2 py-4 text-center text-muted">{{ $t('profitability.no_data') }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </VCard>

                        <VCard :title="$t('profitability.day_breakdown')">
                            <div class="max-h-96 overflow-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-line text-xs uppercase text-muted">
                                            <th class="px-2 py-2 text-start font-medium">{{ $t('profitability.col_date') }}</th>
                                            <th class="px-2 py-2 text-end font-medium">{{ $t('profitability.col_hours') }}</th>
                                            <th class="px-2 py-2 text-end font-medium">{{ $t('profitability.col_day_revenue') }}</th>
                                            <th class="px-2 py-2 text-end font-medium">{{ $t('profitability.col_day_cost') }}</th>
                                            <th class="px-2 py-2 text-end font-medium">{{ $t('profitability.col_day_profit') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(d, i) in report.selected.day_breakdown" :key="i" class="border-b border-line">
                                            <td class="px-2 py-1.5">{{ d.date }}</td>
                                            <td class="tabular-nums px-2 py-1.5 text-end">{{ d.hours }}</td>
                                            <td class="tabular-nums px-2 py-1.5 text-end">{{ d.revenue !== null ? eur(d.revenue) : '—' }}</td>
                                            <td class="tabular-nums px-2 py-1.5 text-end">{{ eur(d.cost) }}</td>
                                            <td class="tabular-nums px-2 py-1.5 text-end">{{ d.profit !== null ? eur(d.profit) : '—' }}</td>
                                        </tr>
                                        <tr v-if="!report.selected.day_breakdown.length"><td colspan="5" class="px-2 py-4 text-center text-muted">{{ $t('profitability.no_data') }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </VCard>
                    </div>
                </template>
            </template>

            <!-- Primary detail table -->
            <VCard v-else-if="table" class="mt-6">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs text-muted">{{ table.length }} {{ $t('reports.rows') }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-start text-xs uppercase text-muted">
                                <th v-for="col in tableColumns" :key="col" class="px-2 py-2 text-start font-medium">
                                    {{ columnLabel(col) }}
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
