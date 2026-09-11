<script setup>
/**
 * Screen 03 — Dashboard. 8 KPI cards, 3 charts, quick actions, and the
 * expiring-documents + recent-activity panels. All figures are for the
 * selected company and arrive pre-computed from DashboardService.
 */
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VKpiCard from '@/Components/ui/VKpiCard.vue';
import VCard from '@/Components/ui/VCard.vue';
import VChart from '@/Components/ui/VChart.vue';
import VStatusDot from '@/Components/ui/VStatusDot.vue';
import VButton from '@/Components/ui/VButton.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
// $t is a template-only global; the script needs the imported helpers.
import { t, tPair } from '@/translate';

const props = defineProps({
    data: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
});

const kpis = computed(() => props.data.kpis);
const charts = computed(() => props.data.charts);

function eur(value) {
    return `${Number(value ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

const attendanceToday = computed(
    () => `${kpis.value.attendance_present_today} / ${kpis.value.attendance_total_today}`,
);

// Revenue-vs-expenses bar
const revenueDatasets = computed(() => [
    { key: 'revenue', label: tPair('dashboard.revenue'), data: charts.value.revenue_vs_expenses.revenue, role: 'revenue' },
    { key: 'expenses', label: tPair('dashboard.expenses'), data: charts.value.revenue_vs_expenses.expenses, role: 'expense' },
]);

// Project-status donut — labels + one colour role per slice
const projectStatusOrder = ['active', 'in_progress', 'completed', 'cancelled', 'on_hold'];
const projectStatusRole = {
    active: 'ok', in_progress: 'inprogress', completed: 'info', cancelled: 'danger', on_hold: 'neutral',
};
const projectLabels = computed(() => projectStatusOrder.map((s) => t(`projects.status_${s}`)));
const projectData = computed(() => projectStatusOrder.map((s) => charts.value.project_status[s] ?? 0));
const projectRoles = projectStatusOrder.map((s) => projectStatusRole[s]);

// Attendance-trend line — compact date labels (dd/mm)
const attendanceLabels = computed(
    () => charts.value.attendance_trend.labels.map((d) => d.slice(8, 10) + '/' + d.slice(5, 7)),
);
const attendanceDatasets = computed(() => [
    { key: 'present', label: t('dashboard.present'), data: charts.value.attendance_trend.present, role: 'accent' },
]);

function go(routeName) {
    router.visit(route(routeName));
}
</script>

<template>
    <Head :title="$t('nav.dashboard')" />

    <AppLayout>
        <VPageHeader k="nav.dashboard" />

        <!-- KPI Row 1 -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <VKpiCard k="dashboard.active_employees" icon="employees" :value="kpis.active_employees" />
            <VKpiCard k="dashboard.active_projects" icon="projects" :value="kpis.active_projects" />
            <VKpiCard k="dashboard.pending_invoices" icon="invoices" :value="eur(kpis.pending_invoices_eur)" />
            <VKpiCard k="dashboard.documents_expiring" icon="file" :value="kpis.documents_expiring"
                :status="kpis.documents_expiring > 0 ? 'warn' : null" />
        </div>

        <!-- KPI Row 2 -->
        <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <VKpiCard k="dashboard.attendance_today" icon="attendance" :value="attendanceToday" />
            <VKpiCard k="dashboard.expenses_this_month" icon="expenses" :value="eur(kpis.expenses_this_month_eur)" />
            <VKpiCard k="dashboard.billing_this_month" icon="invoices" :value="eur(kpis.billing_this_month_eur)" />
            <VKpiCard k="dashboard.deployed_employees" icon="deployments" :value="kpis.deployed_employees" />
        </div>

        <!-- Charts -->
        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <VCard class="lg:col-span-2">
                <h2 class="mb-3 text-sm font-semibold text-ink"><Bilingual k="dashboard.chart_revenue_expenses" inline /></h2>
                <VChart type="bar" :labels="charts.revenue_vs_expenses.labels" :datasets="revenueDatasets" currency />
            </VCard>
            <VCard>
                <h2 class="mb-3 text-sm font-semibold text-ink"><Bilingual k="dashboard.chart_project_status" inline /></h2>
                <VChart type="doughnut" :labels="projectLabels" :datasets="[{ data: projectData }]" :slice-roles="projectRoles" />
            </VCard>
            <VCard>
                <h2 class="mb-3 text-sm font-semibold text-ink"><Bilingual k="dashboard.chart_attendance_trend" inline /></h2>
                <VChart type="line" :labels="attendanceLabels" :datasets="attendanceDatasets" />
            </VCard>
        </div>

        <!-- Quick actions -->
        <div class="mt-6">
            <h2 class="mb-3 text-sm font-semibold text-ink"><Bilingual k="dashboard.quick_actions" inline /></h2>
            <div class="flex flex-wrap gap-2">
                <VButton v-if="can.create_employee" variant="secondary" @click="go('employees.index')">
                    <AppIcon name="employees" class="me-1.5 h-4 w-4" /><Bilingual k="dashboard.add_employee" inline />
                </VButton>
                <VButton v-if="can.create_project" variant="secondary" @click="go('projects.index')">
                    <AppIcon name="projects" class="me-1.5 h-4 w-4" /><Bilingual k="dashboard.new_project" inline />
                </VButton>
                <VButton v-if="can.upload_document" variant="secondary" @click="go('compliance.index')">
                    <AppIcon name="documents" class="me-1.5 h-4 w-4" /><Bilingual k="dashboard.upload_document" inline />
                </VButton>
                <VButton v-if="can.log_attendance" variant="secondary" @click="go('attendance.index')">
                    <AppIcon name="attendance" class="me-1.5 h-4 w-4" /><Bilingual k="dashboard.log_attendance" inline />
                </VButton>
            </div>
        </div>

        <!-- Profitability widget -->
        <div v-if="data.profitability" class="mt-6">
            <h2 class="mb-3 text-sm font-semibold text-ink"><Bilingual k="dashboard.profitability_panel" inline /></h2>
            <div class="grid grid-cols-3 gap-4">
                <VCard class="text-center">
                    <p class="tabular-nums text-3xl font-semibold text-status-ok">{{ data.profitability.profitable }}</p>
                    <p class="mt-1 text-xs text-ink-soft">🟢 {{ $t('dashboard.profit_profitable') }}</p>
                </VCard>
                <VCard class="text-center">
                    <p class="tabular-nums text-3xl font-semibold text-status-warn">{{ data.profitability.at_risk }}</p>
                    <p class="mt-1 text-xs text-ink-soft">🟡 {{ $t('dashboard.profit_at_risk') }}</p>
                </VCard>
                <VCard class="text-center">
                    <p class="tabular-nums text-3xl font-semibold text-status-danger">{{ data.profitability.loss }}</p>
                    <p class="mt-1 text-xs text-ink-soft">🔴 {{ $t('dashboard.profit_loss') }}</p>
                </VCard>
            </div>
        </div>

        <!-- Bottom panels -->
        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <VCard>
                <h2 class="mb-3 text-sm font-semibold text-ink"><Bilingual k="dashboard.documents_expiring_panel" inline /></h2>
                <VEmptyState v-if="data.expiring.length === 0" title-key="dashboard.no_expiring" message-key="dashboard.no_expiring" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="doc in data.expiring" :key="doc.id" class="flex items-center justify-between gap-2 py-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <VStatusDot :status="doc.status" />
                            <span class="truncate text-sm text-ink">{{ doc.name }}</span>
                        </div>
                        <span class="tabular-nums shrink-0 text-xs"
                            :class="doc.status === 'danger' ? 'text-status-danger' : 'text-ink-soft'">
                            <template v-if="doc.days !== null && doc.days < 0">{{ $t('dashboard.expired') }}</template>
                            <template v-else-if="doc.days !== null">{{ doc.days }} {{ $t('dashboard.days_left') }}</template>
                        </span>
                    </li>
                </ul>
            </VCard>

            <VCard>
                <h2 class="mb-3 text-sm font-semibold text-ink"><Bilingual k="dashboard.recent_activity" inline /></h2>
                <VEmptyState v-if="data.activity.length === 0" title-key="dashboard.no_activity" message-key="dashboard.no_activity" />
                <!-- Fixed height: ~10 rows visible, older items scroll inside the box
                     so the feed never pushes the rest of the dashboard down. -->
                <ul v-else class="max-h-[28rem] divide-y divide-line overflow-y-auto">
                    <li v-for="log in data.activity" :key="log.id" class="flex items-center justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-ink">
                                <span class="font-medium">{{ log.action }}</span>
                                <span v-if="log.entity_name" class="text-ink-soft"> · {{ log.entity_name }}</span>
                            </p>
                            <p class="text-xs text-muted">{{ log.user_name }} · {{ log.module }}</p>
                        </div>
                        <span class="tabular-nums shrink-0 text-xs text-muted">{{ log.created_at?.slice(5, 16) }}</span>
                    </li>
                </ul>
            </VCard>
        </div>
    </AppLayout>
</template>
