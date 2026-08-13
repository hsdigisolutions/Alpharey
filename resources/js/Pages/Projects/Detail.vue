<script setup>
/**
 * Screen 09 — Project Detail, 8 tabs. Phase 3 delivers Resumen, Workers,
 * Documentos, Notas. Asistencia/Mediciones/Facturas/Gastos populate in
 * Phases 4/6. Project notes are IMMUTABLE once saved (no edit/delete).
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectFormModal from '@/Components/Projects/ProjectFormModal.vue';
import DocumentsPanel from '@/Components/Documents/DocumentsPanel.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAlert from '@/Components/ui/VAlert.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import AttendanceModal from '@/Components/Attendance/AttendanceModal.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VTimeline from '@/Components/ui/VTimeline.vue';
import VTimelineItem from '@/Components/ui/VTimelineItem.vue';
import VFinanceRows from '@/Components/ui/VFinanceRows.vue';

const props = defineProps({
    project: { type: Object, required: true },
    workers: { type: Array, required: true },
    documents: { type: Array, required: true },
    documentSets: { type: Object, required: true },
    remarks: { type: Array, required: true },
    alerts: { type: Array, required: true },
    availableEmployees: { type: Array, required: true },
    clients: { type: Array, required: true },
    vatOptions: { type: Array, required: true },
    invoices: { type: Array, default: () => [] },
    expenses: { type: Array, default: () => [] },
    canViewInvoices: { type: Boolean, default: false },
    canViewExpenses: { type: Boolean, default: false },
    canSeeWages: { type: Boolean, default: false },
    profitability: { type: Object, default: null },
    dailyPnl: { type: Object, default: null },
    designationRates: { type: Array, default: () => [] },
    designations: { type: Array, default: () => [] },
    rateTypes: { type: Array, default: () => [] },
    // Attendance tab
    projectAttendance: { type: Object, default: null },
    attendanceMonth: { type: String, default: '' },
    attendanceEntryEmployees: { type: Array, default: () => [] },
    canManageAttendance: { type: Boolean, default: false },
    canSeeAttendance: { type: Boolean, default: false },
    // Measurements tab
    projectMeasurements: { type: Object, default: null },
    measurementTypes: { type: Array, default: () => [] },
    measurementEmployees: { type: Array, default: () => [] },
    canManageMeasurements: { type: Object, default: () => ({}) },
    can: { type: Object, required: true },
});

const tab = ref('summary');
const showEdit = ref(false);

const tabs = [
    { key: 'summary', labelKey: 'projects.tab_summary' },
    ...(props.canSeeWages ? [{ key: 'profitability', labelKey: 'projects.tab_profitability' }] : []),
    { key: 'workers', labelKey: 'projects.tab_workers', count: props.workers.length },
    { key: 'attendance', labelKey: 'projects.tab_attendance' },
    { key: 'measurements', labelKey: 'projects.tab_measurements' },
    { key: 'invoices', labelKey: 'projects.tab_invoices' },
    { key: 'expenses', labelKey: 'projects.tab_expenses' },
    { key: 'documents', labelKey: 'projects.tab_documents', count: props.documents.filter((d) => d.has_file).length },
    { key: 'notes', labelKey: 'projects.tab_notes', count: props.remarks.length },
];

const statusBadge = { active: 'ok', in_progress: 'info', completed: 'ok', cancelled: 'danger', on_hold: 'warn' };
const priorityBadge = { low: 'neutral', medium: 'info', high: 'warn', urgent: 'danger' };
const canSeeWages = props.can.edit;

// Profitability formatting. Margin colour: > 15 % green · 5–15 % amber ·
// < 5 % or negative red — mirrors the server's classification.
function eur(value) {
    if (value === null || value === undefined) return '—';
    return `${Number(value).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}
const marginTone = {
    ok: 'text-status-ok', warn: 'text-status-warn', danger: 'text-status-danger', neutral: 'text-ink-soft',
};

const contactCards = [
    { k: 'projects.jefe_de_obra', name: props.project.jefe_de_obra, phone: props.project.jefe_phone, email: props.project.jefe_email },
    { k: 'projects.encargado', name: props.project.encargado },
    { k: 'projects.seguridad', name: props.project.seguridad },
    { k: 'projects.coordinator', name: props.project.coordinator },
];

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }

// workers
const workerForm = useForm({ employee_id: '', project_rate: null });
function addWorker() { workerForm.post(`/projects/${props.project.id}/workers`, { preserveScroll: true, onSuccess: () => workerForm.reset() }); }
function removeWorker(worker) {
    askDelete(worker.name ?? '',
        () => router.delete(`/projects/${props.project.id}/workers/${worker.id}`, { preserveScroll: true }));
}

// designation rates (Feature 2)
const rateForm = useForm({ designation_id: '', client_rate: null, worker_rate: null, rate_type: 'per_hour' });
function addRate() {
    rateForm.post(`/projects/${props.project.id}/designation-rates`, {
        preserveScroll: true, onSuccess: () => { rateForm.reset(); rateForm.rate_type = 'per_hour'; },
    });
}
function removeRate(r) {
    askDelete(r.designation ?? '',
        () => router.delete(`/projects/${props.project.id}/designation-rates/${r.id}`, { preserveScroll: true }));
}
function rateTypeLabel(t) {
    return { per_hour: '€/h', per_day: '€/día', per_meter: '€/m²' }[t] ?? t;
}

// daily / monthly P&L (Feature 3)
const pnlView = ref('daily');
const expandedDay = ref(null);
function toggleDay(date) { expandedDay.value = expandedDay.value === date ? null : date; }
function hoursHM(h) {
    const hh = Math.floor(Math.max(0, Number(h) || 0));
    const mm = Math.round((Math.max(0, Number(h) || 0) - hh) * 60);
    return `${hh}h ${String(mm).padStart(2, '0')}m`;
}
function pct(v) { return `${Number(v ?? 0).toFixed(1)}%`; }
// Row background by margin: >15 green · 5–15 amber · <5/neg red.
function rowTone(margin) {
    const m = Number(margin);
    if (m > 15) return 'bg-status-ok-soft';
    if (m >= 5) return 'bg-status-warn-soft';
    return 'bg-status-danger-soft';
}

// ── Attendance tab ──────────────────────────────────────────────────────────
function attMonthNav(delta) {
    const [y, m] = props.attendanceMonth.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const mm = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(`/projects/${props.project.id}`, { att_month: mm },
        { preserveScroll: true, preserveState: true, only: ['projectAttendance', 'attendanceMonth'] });
}
const attMonthLabel = computed(() => {
    const [y, m] = (props.attendanceMonth || '').split('-').map(Number);
    if (!y) return '';
    return new Date(y, m - 1, 1).toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
});
const attSearch = ref('');
const attDayType = ref('');
const attRecords = computed(() => {
    let rows = props.projectAttendance?.records ?? [];
    if (attSearch.value) rows = rows.filter((r) => (r.employee ?? '').toLowerCase().includes(attSearch.value.toLowerCase()));
    if (attDayType.value) rows = rows.filter((r) => r.day_type === attDayType.value);
    return rows;
});
const showAttEntry = ref(false);
function reloadAfterEntry() {
    router.reload({ only: ['projectAttendance', 'dailyPnl', 'profitability'], preserveScroll: true });
}

// ── Measurements tab ────────────────────────────────────────────────────────
const measSearch = ref('');
const measApproval = ref('all');
const measStatusVariant = { pending: 'warn', approved: 'ok', rejected: 'danger' };
const measRecords = computed(() => {
    let rows = props.projectMeasurements?.records ?? [];
    if (measSearch.value) rows = rows.filter((r) => (r.employee ?? '').toLowerCase().includes(measSearch.value.toLowerCase()));
    if (measApproval.value !== 'all') rows = rows.filter((r) => r.status === measApproval.value);
    return rows;
});
const measUnits = ['m²', 'm', 'm³', 'kg', 'units'];
const measModalOpen = ref(false);
const measEditing = ref(null);
const measForm = useForm({ employee_id: '', date: null, quantity: null, unit: 'm²', measurement_type: 'area', notes: '' });
function openMeas(m = null) {
    measEditing.value = m;
    measForm.clearErrors();
    if (m) {
        measForm.employee_id = m.employee_id ?? '';
        measForm.date = m.date;
        measForm.quantity = m.quantity;
        measForm.unit = m.unit ?? 'm²';
        measForm.measurement_type = m.type;
        measForm.notes = m.notes ?? '';
    } else {
        measForm.reset();
        measForm.unit = 'm²';
        measForm.measurement_type = 'area';
    }
    measModalOpen.value = true;
}
function submitMeas() {
    const opts = { preserveScroll: true, onSuccess: () => { measModalOpen.value = false; measForm.reset(); } };
    measForm.transform((d) => ({ ...d, project_id: props.project.id, employee_id: d.employee_id || null }));
    if (measEditing.value) measForm.put(`/measurements/${measEditing.value.id}`, opts);
    else measForm.post('/measurements', opts);
}
function approveMeas(m) { router.post(`/measurements/${m.id}/approve`, {}, { preserveScroll: true }); }
function resetMeas(m) { router.post(`/measurements/${m.id}/reset`, {}, { preserveScroll: true }); }
function deleteMeas(m) {
    askDelete(m.employee ?? '', () => router.delete(`/measurements/${m.id}`, { preserveScroll: true }));
}

// Reject a measurement with a reason.
const measRejectForm = useForm({ rejection_reason: '' });
const measRejecting = ref(null);
function openRejectMeas(m) { measRejecting.value = m; measRejectForm.reset(); measRejectForm.clearErrors(); }
function submitRejectMeas() {
    measRejectForm.post(`/measurements/${measRejecting.value.id}/reject`, { preserveScroll: true, onSuccess: () => (measRejecting.value = null) });
}

// immutable notes
const noteForm = useForm({ type: 'internal', body: '', noted_at: null });
function addNote() { noteForm.post(`/projects/${props.project.id}/remarks`, { preserveScroll: true, onSuccess: () => noteForm.reset() }); }

const noteStatus = { internal: 'neutral', client_call: 'info', client_email: 'accent', meeting: 'warn', message: 'neutral' };

function destroy() {
    askDelete(props.project.name,
        () => router.delete(`/projects/${props.project.id}`));
}
</script>

<template>
    <Head :title="project.name" />
    <AppLayout>
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ project.name }}</h1>
                <p class="tabular-nums text-sm text-muted">{{ project.code }} · {{ project.company }} · {{ project.client ?? '—' }}</p>
            </div>
            <VBadge :status="statusBadge[project.status]"><Bilingual :k="`projects.status_${project.status}`" inline /></VBadge>
            <VBadge :status="priorityBadge[project.priority]"><Bilingual :k="`projects.priority_${project.priority}`" inline /></VBadge>
            <VButton v-if="can.edit" variant="secondary" icon="edit" @click="showEdit = true"><Bilingual k="common.actions" inline /></VButton>
        </div>

        <VTabs v-model="tab" :tabs="tabs" />

        <div class="mt-5">
            <!-- Resumen -->
            <div v-if="tab === 'summary'" class="grid gap-5 lg:grid-cols-2">
                <!-- Rentabilidad (P&L) — gated by the wage right server-side -->
                <VCard v-if="profitability" title-key="profitability.title" class="lg:col-span-2">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <dl class="divide-y divide-line text-sm">
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.client_billing') }}</dt>
                                <dd class="tabular-nums">{{ profitability.client_hour_rate !== null ? `${eur(profitability.client_hour_rate)}/h` : '—' }}</dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.labour_cost') }} <span class="text-muted">{{ $t('profitability.avg') }}</span></dt>
                                <dd class="tabular-nums">{{ profitability.avg_cost_per_hour !== null ? `${eur(profitability.avg_cost_per_hour)}/h` : '—' }}</dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.margin_per_hour') }}</dt>
                                <dd class="tabular-nums font-medium" :class="marginTone[profitability.health]">
                                    {{ profitability.margin_per_hour !== null ? `${eur(profitability.margin_per_hour)}/h` : '—' }}
                                </dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.total_hours') }}</dt>
                                <dd class="tabular-nums">{{ profitability.hours }} h</dd>
                            </div>
                        </dl>
                        <dl class="divide-y divide-line text-sm">
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.total_revenue') }}</dt>
                                <dd class="tabular-nums font-medium">{{ eur(profitability.revenue) }}</dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.labour_cost') }}</dt>
                                <dd class="tabular-nums">{{ eur(profitability.labour_cost) }}</dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.other_expenses') }}</dt>
                                <dd class="tabular-nums">{{ eur(profitability.expenses) }}</dd>
                            </div>
                            <div v-if="profitability.subcontractor_cost > 0" class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.subcontractor') }}</dt>
                                <dd class="tabular-nums">{{ eur(profitability.subcontractor_cost) }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface-sunken px-4 py-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-muted">{{ $t('profitability.gross_profit') }}</p>
                            <p class="tabular-nums text-2xl font-semibold" :class="marginTone[profitability.health]">{{ eur(profitability.profit) }}</p>
                        </div>
                        <div class="text-end">
                            <p class="text-xs uppercase tracking-wide text-muted">{{ $t('profitability.margin') }}</p>
                            <p class="tabular-nums text-2xl font-semibold" :class="marginTone[profitability.health]">
                                {{ profitability.margin !== null ? `${profitability.margin.toLocaleString('es-ES')} %` : '—' }}
                            </p>
                        </div>
                    </div>
                    <p v-if="profitability.outsourced" class="mt-3 text-xs text-ink-soft">{{ $t('profitability.outsourced_note') }}</p>
                </VCard>

                <VCard title-key="projects.section_budget">
                    <dl class="divide-y divide-line">
                        <div class="flex justify-between py-2"><dt><Bilingual k="projects.budget" class="text-xs text-muted" /></dt><dd class="tabular-nums text-sm">{{ project.budget ?? '—' }}</dd></div>
                        <div class="flex justify-between py-2"><dt><Bilingual k="projects.billing_type" class="text-xs text-muted" /></dt><dd class="text-sm">{{ project.billing_type ? $t(`projects.billing_${project.billing_type}`) : '—' }}</dd></div>
                        <div class="flex justify-between py-2"><dt><Bilingual k="projects.vat" class="text-xs text-muted" /></dt><dd class="text-sm">{{ project.vat_rate ? $t(`vat.${project.vat_rate}`) : $t('vat.not_applicable') }}</dd></div>
                        <div class="flex justify-between py-2"><dt><Bilingual k="projects.start" class="text-xs text-muted" /></dt><dd class="tabular-nums text-sm">{{ project.start_date ?? '—' }} → {{ project.end_date ?? '—' }}</dd></div>
                    </dl>
                    <p v-if="project.description" class="mt-3 text-sm text-ink-soft">{{ project.description }}</p>
                </VCard>
                <VCard title-key="projects.section_contacts">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div v-for="c in contactCards" :key="c.k" class="rounded-md border border-line p-3">
                            <Bilingual :k="c.k" class="text-xs text-muted" />
                            <p class="mt-0.5 text-sm font-medium">{{ c.name ?? '—' }}</p>
                            <p v-if="c.phone" class="text-xs text-muted">{{ c.phone }}</p>
                            <p v-if="c.email" class="text-xs text-muted">{{ c.email }}</p>
                        </div>
                    </div>
                </VCard>

                <!-- Per-designation CLIENT billing rates. worker_rate is reference
                     only — pay always comes from the profile/wage history. -->
                <VCard v-if="canSeeWages" title-key="project_rates.title" class="lg:col-span-2" :padded="false">
                    <p class="border-b border-line px-4 py-2 text-xs text-muted">{{ $t('project_rates.worker_rate_hint') }}</p>
                    <table class="w-full text-sm">
                        <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                            <tr>
                                <th class="px-4 py-2 text-start"><Bilingual k="project_rates.designation" inline /></th>
                                <th class="px-4 py-2 text-end"><Bilingual k="project_rates.client_rate" inline /></th>
                                <th class="px-4 py-2 text-end" :title="$t('project_rates.worker_rate_hint')"><Bilingual k="project_rates.worker_rate" inline /></th>
                                <th class="px-4 py-2 text-start"><Bilingual k="project_rates.rate_type" inline /></th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in designationRates" :key="r.id" class="border-b border-line">
                                <td class="px-4 py-2.5">{{ r.designation }}</td>
                                <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(r.client_rate) }} {{ rateTypeLabel(r.rate_type) }}</td>
                                <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(r.worker_rate) }} {{ rateTypeLabel(r.rate_type) }}</td>
                                <td class="px-4 py-2.5 text-ink-soft">{{ $t(`project_rates.type_${r.rate_type}`) }}</td>
                                <td class="px-4 py-2.5 text-end">
                                    <VButton v-if="can.edit" variant="ghost" size="sm" icon="trash" @click="removeRate(r)" />
                                </td>
                            </tr>
                            <tr v-if="designationRates.length === 0">
                                <td colspan="5" class="px-4 py-4 text-center text-sm text-muted">{{ $t('project_rates.empty') }}</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="can.edit">
                            <tr class="border-t border-line bg-surface-sunken/40">
                                <td class="px-3 py-2">
                                    <VSelect v-model="rateForm.designation_id" class="w-full">
                                        <option value="">—</option>
                                        <option v-for="d in designations" :key="d.id" :value="d.id">{{ d.name }}</option>
                                    </VSelect>
                                </td>
                                <td class="px-3 py-2"><VInput v-model="rateForm.client_rate" type="number" step="0.01" min="0" class="w-24" /></td>
                                <td class="px-3 py-2"><VInput v-model="rateForm.worker_rate" type="number" step="0.01" min="0" class="w-24" /></td>
                                <td class="px-3 py-2">
                                    <VSelect v-model="rateForm.rate_type" class="w-full">
                                        <option v-for="t in rateTypes" :key="t" :value="t">{{ $t(`project_rates.type_${t}`) }}</option>
                                    </VSelect>
                                </td>
                                <td class="px-3 py-2 text-end">
                                    <VButton size="sm" icon="plus" :loading="rateForm.processing" @click="addRate"><Bilingual k="project_rates.add" inline /></VButton>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </VCard>
            </div>

            <!-- Rentabilidad — daily / monthly production P&L (Feature 3) -->
            <div v-else-if="tab === 'profitability'" class="space-y-5">
                <!-- KPI cards -->
                <div v-if="dailyPnl" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <VCard>
                        <p class="text-xs text-muted">{{ $t('profitability.kpi_today') }}</p>
                        <p class="mt-1 text-lg font-semibold" :class="dailyPnl.kpis.today.profit >= 0 ? 'text-status-ok' : 'text-status-danger'">
                            {{ eur(dailyPnl.kpis.today.profit) }} <span class="text-xs">({{ pct(dailyPnl.kpis.today.margin) }})</span>
                        </p>
                    </VCard>
                    <VCard>
                        <p class="text-xs text-muted">{{ $t('profitability.kpi_month') }}</p>
                        <p class="mt-1 text-lg font-semibold" :class="dailyPnl.kpis.this_month.profit >= 0 ? 'text-status-ok' : 'text-status-danger'">
                            {{ eur(dailyPnl.kpis.this_month.profit) }} <span class="text-xs">({{ pct(dailyPnl.kpis.this_month.margin) }})</span>
                        </p>
                    </VCard>
                    <VCard>
                        <p class="text-xs text-muted">{{ $t('profitability.kpi_total') }}</p>
                        <p class="mt-1 text-lg font-semibold" :class="dailyPnl.kpis.total.profit >= 0 ? 'text-status-ok' : 'text-status-danger'">
                            {{ eur(dailyPnl.kpis.total.profit) }} <span class="text-xs">({{ pct(dailyPnl.kpis.total.margin) }})</span>
                        </p>
                    </VCard>
                    <VCard>
                        <p class="text-xs text-muted">{{ $t('profitability.kpi_days_left') }}</p>
                        <p class="mt-1 text-lg font-semibold text-ink">{{ dailyPnl.kpis.days_remaining ?? '—' }}</p>
                    </VCard>
                </div>

                <!-- Daily / monthly toggle -->
                <div class="flex gap-2">
                    <VButton :variant="pnlView === 'daily' ? 'primary' : 'secondary'" size="sm" @click="pnlView = 'daily'"><Bilingual k="profitability.view_daily" inline /></VButton>
                    <VButton :variant="pnlView === 'monthly' ? 'primary' : 'secondary'" size="sm" @click="pnlView = 'monthly'"><Bilingual k="profitability.view_monthly" inline /></VButton>
                </div>

                <!-- Daily table with expandable per-worker rows -->
                <VCard v-if="pnlView === 'daily'" :padded="false">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                                <tr>
                                    <th class="px-4 py-2 text-start"><Bilingual k="profitability.date" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.workers" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.total_hours" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.income" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.labour_cost" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.other_expenses" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.profit" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.margin" inline /></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="d in dailyPnl.days" :key="d.date">
                                    <tr class="cursor-pointer border-b border-line" :class="rowTone(d.margin)" @click="toggleDay(d.date)">
                                        <td class="px-4 py-2.5 font-medium">{{ d.date }}</td>
                                        <td class="tabular-nums px-4 py-2.5 text-end">{{ d.workers_count }}</td>
                                        <td class="tabular-nums px-4 py-2.5 text-end">{{ hoursHM(d.hours) }}</td>
                                        <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(d.income) }}</td>
                                        <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(d.labour) }}</td>
                                        <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(d.expenses) }}</td>
                                        <td class="tabular-nums px-4 py-2.5 text-end font-semibold">{{ eur(d.profit) }}</td>
                                        <td class="tabular-nums px-4 py-2.5 text-end">{{ pct(d.margin) }}</td>
                                    </tr>
                                    <tr v-if="expandedDay === d.date">
                                        <td colspan="8" class="bg-surface-sunken/40 px-4 py-3">
                                            <table class="w-full text-xs">
                                                <thead class="text-[10px] uppercase text-muted">
                                                    <tr>
                                                        <th class="py-1 text-start"><Bilingual k="profitability.worker" inline /></th>
                                                        <th class="py-1 text-start"><Bilingual k="profitability.designation" inline /></th>
                                                        <th class="py-1 text-end"><Bilingual k="profitability.total_hours" inline /></th>
                                                        <th class="py-1 text-end"><Bilingual k="profitability.client_rate" inline /></th>
                                                        <th class="py-1 text-end"><Bilingual k="profitability.worker_rate" inline /></th>
                                                        <th class="py-1 text-end"><Bilingual k="profitability.income" inline /></th>
                                                        <th class="py-1 text-end"><Bilingual k="profitability.labour_cost" inline /></th>
                                                        <th class="py-1 text-end"><Bilingual k="profitability.profit" inline /></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="(w, i) in d.workers" :key="i" class="border-t border-line">
                                                        <td class="py-1">{{ w.worker }}</td>
                                                        <td class="py-1 text-ink-soft">{{ w.designation ?? '—' }}</td>
                                                        <td class="tabular-nums py-1 text-end">{{ hoursHM(w.hours) }}</td>
                                                        <td class="tabular-nums py-1 text-end">{{ eur(w.client_rate) }}</td>
                                                        <td class="tabular-nums py-1 text-end">{{ eur(w.worker_rate) }}</td>
                                                        <td class="tabular-nums py-1 text-end">{{ eur(w.income) }}</td>
                                                        <td class="tabular-nums py-1 text-end">{{ eur(w.cost) }}</td>
                                                        <td class="tabular-nums py-1 text-end font-medium">{{ eur(w.profit) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="dailyPnl.days.length === 0"><td colspan="8" class="px-4 py-6 text-center text-muted">{{ $t('profitability.no_data') }}</td></tr>
                                <tr v-else class="border-t-2 border-line-strong font-semibold">
                                    <td class="px-4 py-2.5">{{ $t('profitability.total') }}</td>
                                    <td></td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ hoursHM(dailyPnl.totals.hours) }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(dailyPnl.totals.income) }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(dailyPnl.totals.labour) }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(dailyPnl.totals.expenses) }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(dailyPnl.totals.profit) }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ pct(dailyPnl.totals.margin) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </VCard>

                <!-- Monthly summary -->
                <VCard v-else :padded="false">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                                <tr>
                                    <th class="px-4 py-2 text-start"><Bilingual k="profitability.month" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.days_worked" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.income" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.labour_cost" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.other_expenses" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.profit" inline /></th>
                                    <th class="px-4 py-2 text-end"><Bilingual k="profitability.margin" inline /></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in dailyPnl.months" :key="m.month" class="border-b border-line" :class="rowTone(m.margin)">
                                    <td class="px-4 py-2.5 font-medium">{{ m.month }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ m.days_worked }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(m.income) }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(m.labour) }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ eur(m.expenses) }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end font-semibold">{{ eur(m.profit) }}</td>
                                    <td class="tabular-nums px-4 py-2.5 text-end">{{ pct(m.margin) }}</td>
                                </tr>
                                <tr v-if="dailyPnl.months.length === 0"><td colspan="7" class="px-4 py-6 text-center text-muted">{{ $t('profitability.no_data') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </VCard>
            </div>

            <!-- Workers -->
            <div v-else-if="tab === 'workers'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard :padded="false">
                    <table v-if="workers.length" class="w-full text-sm">
                        <tbody class="divide-y divide-line">
                            <tr v-for="w in workers" :key="w.id" class="hover:bg-surface-hover">
                                <td class="px-4 py-2.5 font-medium">{{ w.name }}</td>
                                <td class="px-4 py-2.5 text-ink-soft">{{ w.designation ?? '—' }}</td>
                                <td class="tabular-nums px-4 py-2.5 text-ink-soft">{{ canSeeWages ? (w.project_rate ?? '—') : '•••' }}</td>
                                <td class="px-4 py-2.5 text-end">
                                    <button v-if="can.edit" type="button" class="text-xs text-status-danger hover:underline" @click="removeWorker(w)">
                                        <Bilingual k="documents.delete" inline />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <VEmptyState v-else icon="employees" />
                </VCard>
                <VCard v-if="can.edit" title-key="projects.add_worker">
                    <form class="space-y-3" @submit.prevent="addWorker">
                        <FormField k="projects.tab_workers" :error="workerForm.errors.employee_id" required>
                            <VSelect v-model="workerForm.employee_id">
                                <option value="">—</option>
                                <option v-for="e in availableEmployees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                            </VSelect>
                        </FormField>
                        <FormField v-if="canSeeWages" k="projects.project_rate"><VCurrencyInput v-model="workerForm.project_rate" /></FormField>
                        <VButton type="submit" class="w-full" :loading="workerForm.processing"><Bilingual k="common.save" inline /></VButton>
                    </form>
                </VCard>
            </div>

            <!-- Documentos -->
            <DocumentsPanel v-else-if="tab === 'documents'" entity-type="project" :entity-id="project.id"
                :documents="documents" :sets="documentSets" :can="can" />

            <!-- Notas (immutable) -->
            <div v-else-if="tab === 'notes'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard>
                    <VTimeline v-if="remarks.length">
                        <VTimelineItem v-for="r in remarks" :key="r.id" :time="r.noted_at" :author="r.author"
                            :type-label="$t(`projects.note_${r.type}`)" :type-status="noteStatus[r.type]">
                            {{ r.body }}
                        </VTimelineItem>
                    </VTimeline>
                    <VEmptyState v-else icon="file" />
                </VCard>
                <VCard v-if="can.edit" title-key="projects.add_note">
                    <VAlert status="info" class="mb-3"><Bilingual k="projects.note_immutable" /></VAlert>
                    <form class="space-y-3" @submit.prevent="addNote">
                        <FormField k="projects.tab_notes">
                            <VSelect v-model="noteForm.type">
                                <option v-for="t in ['internal','client_call','client_email','meeting','message']" :key="t" :value="t">
                                    {{ $t(`projects.note_${t}`) }}
                                </option>
                            </VSelect>
                        </FormField>
                        <FormField k="projects.description" :error="noteForm.errors.body" required><VTextarea v-model="noteForm.body" :rows="3" /></FormField>
                        <VButton type="submit" class="w-full" :loading="noteForm.processing"><Bilingual k="common.save" inline /></VButton>
                    </form>
                </VCard>
            </div>

            <!-- Facturas — invoices raised against this project -->
            <VFinanceRows v-else-if="tab === 'invoices'"
                :rows="invoices" :can-view="canViewInvoices" empty-key="finance.no_invoices" />

            <!-- Gastos — expenses booked against this project -->
            <VFinanceRows v-else-if="tab === 'expenses'"
                :rows="expenses" :can-view="canViewExpenses" empty-key="finance.no_expenses" />

            <!-- Asistencia — this project's attendance for the month -->
            <div v-else-if="tab === 'attendance'" class="space-y-4">
                <div v-if="!canSeeAttendance" class="py-8 text-center text-sm text-muted">{{ $t('common.no_permission') }}</div>
                <template v-else>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="attMonthNav(-1)">
                            <AppIcon name="chevron-left" class="h-4 w-4" />
                        </button>
                        <span class="min-w-40 text-center text-sm font-semibold capitalize">{{ attMonthLabel }}</span>
                        <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="attMonthNav(1)">
                            <AppIcon name="chevron-right" class="h-4 w-4" />
                        </button>
                        <VInput v-model="attSearch" :placeholder="$t('projects.filter_employee')" class="w-48" />
                        <VSelect v-model="attDayType" class="w-40">
                            <option value="">{{ $t('attendance.all_day_types') }}</option>
                            <option value="full">{{ $t('attendance.day_type_full') }}</option>
                            <option value="half">{{ $t('attendance.day_type_half') }}</option>
                            <option value="hourly">{{ $t('attendance.day_type_hourly') }}</option>
                        </VSelect>
                        <VButton v-if="canManageAttendance" class="ms-auto" icon="plus" @click="showAttEntry = true">
                            <Bilingual k="attendance.new" inline />
                        </VButton>
                    </div>

                    <VCard :padded="false">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                                    <tr>
                                        <th class="px-3 py-2 text-start"><Bilingual k="attendance.date" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="attendance.employee" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="project_rates.designation" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="attendance.day_type" inline /></th>
                                        <th class="px-3 py-2 text-end"><Bilingual k="attendance.entry" inline /></th>
                                        <th class="px-3 py-2 text-end"><Bilingual k="attendance.exit" inline /></th>
                                        <th class="px-3 py-2 text-end"><Bilingual k="profitability.total_hours" inline /></th>
                                        <th v-if="canSeeWages" class="px-3 py-2 text-end"><Bilingual k="project_rates.rate_type" inline /></th>
                                        <th v-if="canSeeWages" class="px-3 py-2 text-end"><Bilingual k="profitability.labour_cost" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="attendance.status" inline /></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in attRecords" :key="r.id" class="border-b border-line hover:bg-surface-hover">
                                        <td class="tabular-nums px-3 py-2">{{ r.date }}</td>
                                        <td class="px-3 py-2">{{ r.employee }}</td>
                                        <td class="px-3 py-2 text-ink-soft">{{ r.designation ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ r.day_type ? $t(`attendance.day_type_${r.day_type}`) : '—' }}</td>
                                        <td class="tabular-nums px-3 py-2 text-end">{{ r.check_in ?? '—' }}</td>
                                        <td class="tabular-nums px-3 py-2 text-end">{{ r.check_out ?? '—' }}</td>
                                        <td class="tabular-nums px-3 py-2 text-end">{{ hoursHM(r.hours) }}</td>
                                        <td v-if="canSeeWages" class="tabular-nums px-3 py-2 text-end">{{ r.rate !== null ? eur(r.rate) : '—' }}</td>
                                        <td v-if="canSeeWages" class="tabular-nums px-3 py-2 text-end font-medium">{{ r.total !== null ? eur(r.total) : '—' }}</td>
                                        <td class="px-3 py-2"><VBadge :status="{ present: 'ok', absent: 'danger', leave: 'info', late: 'warn' }[r.status] ?? 'neutral'">{{ $t(`attendance.status_${r.status}`) }}</VBadge></td>
                                    </tr>
                                    <tr v-if="attRecords.length === 0"><td :colspan="canSeeWages ? 10 : 8" class="px-3 py-6 text-center text-muted">{{ $t('attendance.no_records') }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </VCard>

                    <div v-if="projectAttendance" class="grid gap-3 sm:grid-cols-4">
                        <VCard><p class="text-xs text-muted">{{ $t('projects.att_workers') }}</p><p class="tabular-nums mt-1 text-lg font-semibold">{{ projectAttendance.summary.workers }}</p></VCard>
                        <VCard><p class="text-xs text-muted">{{ $t('projects.att_days') }}</p><p class="tabular-nums mt-1 text-lg font-semibold">{{ projectAttendance.summary.days }}</p></VCard>
                        <VCard><p class="text-xs text-muted">{{ $t('profitability.total_hours') }}</p><p class="tabular-nums mt-1 text-lg font-semibold">{{ hoursHM(projectAttendance.summary.hours) }}</p></VCard>
                        <VCard v-if="canSeeWages"><p class="text-xs text-muted">{{ $t('profitability.labour_cost') }}</p><p class="tabular-nums mt-1 text-lg font-semibold text-accent">{{ eur(projectAttendance.summary.labour_cost) }}</p></VCard>
                    </div>
                </template>
            </div>

            <!-- Mediciones — this project's measurements -->
            <div v-else-if="tab === 'measurements'" class="space-y-4">
                <div v-if="!canManageMeasurements.view" class="py-8 text-center text-sm text-muted">{{ $t('common.no_permission') }}</div>
                <template v-else>
                    <div class="flex flex-wrap items-center gap-3">
                        <VInput v-model="measSearch" :placeholder="$t('projects.filter_employee')" class="w-48" />
                        <VSelect v-model="measApproval" class="w-40">
                            <option value="all">{{ $t('measurements.all') }}</option>
                            <option value="pending">{{ $t('measurements.pending_f') }}</option>
                            <option value="approved">{{ $t('measurements.approved_f') }}</option>
                            <option value="rejected">{{ $t('measurements.rejected_label') }}</option>
                        </VSelect>
                        <VButton v-if="canManageMeasurements.create" class="ms-auto" icon="plus" @click="openMeas()">
                            <Bilingual k="measurements.add" inline />
                        </VButton>
                    </div>

                    <VCard :padded="false">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                                    <tr>
                                        <th class="px-3 py-2 text-start"><Bilingual k="measurements.date" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="measurements.employee" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="project_rates.designation" inline /></th>
                                        <th class="px-3 py-2 text-end"><Bilingual k="measurements.quantity" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="measurements.unit" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="measurements.type" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="measurements.approved_col" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="measurements.notes" inline /></th>
                                        <th class="px-3 py-2 text-end"><Bilingual k="common.actions" inline /></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="m in measRecords" :key="m.id" class="border-b border-line hover:bg-surface-hover">
                                        <td class="tabular-nums px-3 py-2">{{ m.date }}</td>
                                        <td class="px-3 py-2">{{ m.employee ?? '—' }}</td>
                                        <td class="px-3 py-2 text-ink-soft">{{ m.designation ?? '—' }}</td>
                                        <td class="tabular-nums px-3 py-2 text-end">{{ m.quantity }}</td>
                                        <td class="px-3 py-2">{{ m.unit ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ $t(`measurements.type_${m.type}`) }}</td>
                                        <td class="px-3 py-2">
                                            <VBadge :status="measStatusVariant[m.status]">{{ $t(`measurements.status_${m.status}`) }}</VBadge>
                                            <span v-if="m.status === 'rejected' && m.rejection_reason" class="mt-0.5 block text-xs text-status-danger">{{ m.rejection_reason }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-ink-soft">{{ m.notes ?? '—' }}</td>
                                        <td class="px-3 py-2">
                                            <div class="flex items-center justify-end gap-1">
                                                <VButton v-if="m.status !== 'approved' && canManageMeasurements.approve" variant="ghost" size="sm" @click="approveMeas(m)"><Bilingual k="measurements.approve" inline /></VButton>
                                                <VButton v-if="m.status === 'pending' && canManageMeasurements.approve" variant="ghost" size="sm" @click="openRejectMeas(m)"><Bilingual k="measurements.reject" inline /></VButton>
                                                <VButton v-if="m.status !== 'pending' && canManageMeasurements.approve" variant="ghost" size="sm" @click="resetMeas(m)"><Bilingual k="measurements.reopen" inline /></VButton>
                                                <VButton v-if="m.status !== 'approved' && canManageMeasurements.edit" variant="ghost" size="sm" icon="edit" @click="openMeas(m)" />
                                                <VButton v-if="m.status !== 'approved' && canManageMeasurements.delete" variant="ghost" size="sm" icon="trash" @click="deleteMeas(m)" />
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="measRecords.length === 0"><td colspan="9" class="px-3 py-6 text-center text-muted">{{ $t('measurements.no_records') }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </VCard>

                    <div v-if="projectMeasurements" class="grid gap-3 sm:grid-cols-4">
                        <VCard><p class="text-xs text-muted">{{ $t('measurements.total_approved') }}</p><p class="tabular-nums mt-1 text-lg font-semibold text-status-ok">{{ projectMeasurements.summary.approved_qty }}</p></VCard>
                        <VCard><p class="text-xs text-muted">{{ $t('measurements.total_pending') }}</p><p class="tabular-nums mt-1 text-lg font-semibold text-status-warn">{{ projectMeasurements.summary.pending_qty }}</p></VCard>
                        <VCard><p class="text-xs text-muted">{{ $t('measurements.rejected_label') }}</p><p class="tabular-nums mt-1 text-lg font-semibold text-status-danger">{{ projectMeasurements.summary.rejected_qty }}</p></VCard>
                        <VCard><p class="text-xs text-muted">{{ $t('measurements.billing_linked') }}</p><p class="mt-1 text-lg font-semibold">{{ projectMeasurements.summary.billing_linked ? $t('common.yes') : $t('common.no') }}</p></VCard>
                    </div>

                    <!-- A4 — per-worker breakdown of approved / pending / rejected -->
                    <VCard v-if="projectMeasurements && projectMeasurements.per_worker.length" :padded="false">
                        <p class="border-b border-line px-3 py-2 text-[13px] font-semibold"><Bilingual k="measurements.per_worker" inline /></p>
                        <div class="overflow-x-auto">
                            <table class="tabular-nums w-full text-sm">
                                <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                                    <tr>
                                        <th class="px-3 py-2 text-start"><Bilingual k="measurements.employee" inline /></th>
                                        <th class="px-3 py-2 text-end text-status-ok"><Bilingual k="measurements.w_approved" inline /></th>
                                        <th class="px-3 py-2 text-end text-status-warn"><Bilingual k="measurements.w_pending" inline /></th>
                                        <th class="px-3 py-2 text-end text-status-danger"><Bilingual k="measurements.w_rejected" inline /></th>
                                        <th class="px-3 py-2 text-end"><Bilingual k="measurements.w_total" inline /></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="w in projectMeasurements.per_worker" :key="w.employee_id ?? 'none'" class="border-b border-line last:border-0">
                                        <td class="px-3 py-2">{{ w.employee }}</td>
                                        <td class="px-3 py-2 text-end">{{ w.approved }}</td>
                                        <td class="px-3 py-2 text-end">{{ w.pending }}</td>
                                        <td class="px-3 py-2 text-end">{{ w.rejected }}</td>
                                        <td class="px-3 py-2 text-end font-medium">{{ w.total }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </VCard>
                </template>
            </div>

            <!-- Fallback for any other tab -->
            <VCard v-else>
                <p class="py-8 text-center text-sm text-muted"><Bilingual k="common.coming_soon" class="items-center" /></p>
            </VCard>
        </div>

        <!-- Attendance New Entry (this project preselected) -->
        <AttendanceModal v-if="canManageAttendance" :open="showAttEntry" :record="null"
            :preset-project="project.id" :employees="attendanceEntryEmployees"
            :projects="[{ id: project.id, name: project.name, client_name: project.client }]"
            :can-see-wage="canSeeWages" @close="showAttEntry = false; reloadAfterEntry()" />

        <!-- Add / edit measurement -->
        <VModal :open="measModalOpen" :title-key="measEditing ? 'measurements.edit' : 'measurements.add'" @close="measModalOpen = false">
            <form id="meas-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitMeas">
                <FormField k="measurements.employee" :error="measForm.errors.employee_id" class="sm:col-span-2">
                    <VSelect v-model="measForm.employee_id">
                        <option value="">—</option>
                        <option v-for="e in measurementEmployees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="measurements.date" :error="measForm.errors.date" required>
                    <VInput v-model="measForm.date" type="date" />
                </FormField>
                <FormField k="measurements.quantity" :error="measForm.errors.quantity" required>
                    <VInput v-model="measForm.quantity" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="measurements.unit" :error="measForm.errors.unit">
                    <VSelect v-model="measForm.unit">
                        <option v-for="u in measUnits" :key="u" :value="u">{{ u }}</option>
                    </VSelect>
                </FormField>
                <FormField k="measurements.type" :error="measForm.errors.measurement_type" required>
                    <VSelect v-model="measForm.measurement_type">
                        <option v-for="t in measurementTypes" :key="t" :value="t">{{ $t(`measurements.type_${t}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="measurements.notes" :error="measForm.errors.notes" class="sm:col-span-2">
                    <VTextarea v-model="measForm.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="measModalOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="meas-form" :loading="measForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <!-- Reject a measurement with a reason -->
        <VModal :open="measRejecting !== null" title-key="measurements.reject" @close="measRejecting = null">
            <form id="meas-reject-form" @submit.prevent="submitRejectMeas">
                <FormField k="measurements.rejection_reason" :error="measRejectForm.errors.rejection_reason" required>
                    <VTextarea v-model="measRejectForm.rejection_reason" :rows="3" :placeholder="$t('measurements.rejection_hint')" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="measRejecting = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton variant="danger" type="submit" form="meas-reject-form" :loading="measRejectForm.processing"><Bilingual k="measurements.reject" inline /></VButton>
            </template>
        </VModal>

        <ProjectFormModal :open="showEdit" :project="project" :clients="clients" :vat-options="vatOptions" @close="showEdit = false" />
        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
