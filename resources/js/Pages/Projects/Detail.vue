<script setup>
/**
 * Screen 09 — Project Detail, 8 tabs. Phase 3 delivers Resumen, Workers,
 * Documentos, Notas. Asistencia/Mediciones/Facturas/Gastos populate in
 * Phases 4/6. Project notes are IMMUTABLE once saved (no edit/delete).
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import ProjectFormModal from '@/Components/Projects/ProjectFormModal.vue';
import DocumentsPanel from '@/Components/Documents/DocumentsPanel.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAlert from '@/Components/ui/VAlert.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VChart from '@/Components/ui/VChart.vue';
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
    documentFieldDefs: { type: Object, default: () => ({}) },
    remarks: { type: Array, required: true },
    availableEmployees: { type: Array, required: true },
    clients: { type: Array, required: true },
    vatOptions: { type: Array, required: true },
    invoices: { type: Array, default: () => [] },
    invoiceSummary: { type: Object, default: null },
    canCreateInvoice: { type: Boolean, default: false },
    expenses: { type: Array, default: () => [] },
    expenseBreakdown: { type: Array, default: () => [] },
    canViewInvoices: { type: Boolean, default: false },
    canViewExpenses: { type: Boolean, default: false },
    canSeeWages: { type: Boolean, default: false },
    profitability: { type: Object, default: null },
    dailyPnl: { type: Object, default: null },
    designationRates: { type: Array, default: () => [] },
    designations: { type: Array, default: () => [] },
    rateTypes: { type: Array, default: () => [] },
    // Client-side contacts for this project (supervisor / engineer / PM / other).
    projectContacts: { type: Array, default: () => [] },
    contactRoles: { type: Array, default: () => [] },
    // Active employees of the project's company, for the manager dropdowns.
    employeeOptions: { type: Array, default: () => [] },
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
    projectTasks: { type: Object, default: null },
    taskCategories: { type: Array, default: () => [] },
    taskStatuses: { type: Array, default: () => [] },
    taskTemplates: { type: Array, default: () => [] },
    taskReport: { type: Object, default: null },
    canManageTasks: { type: Object, default: () => ({}) },
    can: { type: Object, required: true },
});

const tab = ref('summary');
const showEdit = ref(false);

// Computed so counts (tasks especially, updated via partial reload) stay live.
const tabs = computed(() => [
    { key: 'summary', labelKey: 'projects.tab_summary' },
    ...(props.canSeeWages ? [{ key: 'profitability', labelKey: 'projects.tab_profitability' }] : []),
    { key: 'workers', labelKey: 'projects.tab_workers', count: props.workers.length },
    { key: 'attendance', labelKey: 'projects.tab_attendance' },
    // Measurements tab removed from the project view (2026-09) — measurements are
    // managed on the standalone /measurements screen (Screen 24), and per-meter
    // income still reads the measurements table directly in ProfitabilityService.
    ...(props.canManageTasks?.view ? [{ key: 'tasks', labelKey: 'projects.tab_tasks', count: props.projectTasks?.tasks.length }] : []),
    { key: 'invoices', labelKey: 'projects.tab_invoices' },
    { key: 'expenses', labelKey: 'projects.tab_expenses' },
    { key: 'documents', labelKey: 'projects.tab_documents', count: props.documents.filter((d) => d.has_file).length },
    { key: 'notes', labelKey: 'projects.tab_notes', count: props.remarks.length },
]);

const statusBadge = { active: 'ok', in_progress: 'info', completed: 'ok', cancelled: 'danger', on_hold: 'warn' };
const priorityBadge = { low: 'neutral', medium: 'info', high: 'warn', urgent: 'danger' };
// Wage-visibility gate comes from the server prop `canSeeWages`
// (payroll.view || employees.edit) — NOT project-edit rights. A local const of
// the same name previously shadowed the prop, gating every money column on
// projects.edit by mistake; it was removed. Edit actions use `can.edit` directly.

// Profitability formatting. Margin colour: > 15 % green · 5–15 % amber ·
// < 5 % or negative red — mirrors the server's classification.
function eur(value) {
    if (value === null || value === undefined) return '—';
    return `${Number(value).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}
const marginTone = {
    ok: 'text-status-ok', warn: 'text-status-warn', danger: 'text-status-danger', neutral: 'text-ink-soft',
};

// Each project person resolves to the linked employee (name · designation ·
// phone), or the legacy free-text fallback the server already merged in.
const contactCards = [
    { k: 'projects.jefe_de_obra', c: props.project.site_manager },
    { k: 'projects.encargado', c: props.project.foreman },
    { k: 'projects.seguridad', c: props.project.safety },
    { k: 'projects.coordinator', c: props.project.coordinator_contact },
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

// Client contacts — simple CRUD (add/edit in a modal, delete inline).
const contactModalOpen = ref(false);
const contactEditing = ref(null);
const contactForm = useForm({ name: '', role: 'supervisor', phone: '', email: '', notes: '' });
function openContact(c = null) {
    contactEditing.value = c;
    contactForm.clearErrors();
    contactForm.name = c?.name ?? '';
    contactForm.role = c?.role ?? 'supervisor';
    contactForm.phone = c?.phone ?? '';
    contactForm.email = c?.email ?? '';
    contactForm.notes = c?.notes ?? '';
    contactModalOpen.value = true;
}
function saveContact() {
    const opts = { preserveScroll: true, onSuccess: () => { contactModalOpen.value = false; } };
    if (contactEditing.value) {
        contactForm.put(`/projects/${props.project.id}/contacts/${contactEditing.value.id}`, opts);
    } else {
        contactForm.post(`/projects/${props.project.id}/contacts`, opts);
    }
}
function removeContact(c) {
    askDelete(c.name ?? '', () => router.delete(`/projects/${props.project.id}/contacts/${c.id}`, { preserveScroll: true }));
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

// ── Production tasks (Tareas tab) ───────────────────────────────────────────
const taskHealth = { ok: 'bg-status-ok', warn: 'bg-status-warn', danger: 'bg-status-danger' };
const taskStatusVariant = { open: 'neutral', in_progress: 'info', done: 'ok' };

// Phase E — production trend chart (one line per category).
const categoryRole = { civil: 'accent', electrical: 'info', plumbing: 'ok', finishing: 'warn', other: 'neutral' };
const trendChart = computed(() => {
    const trend = props.taskReport?.trend;
    if (!trend || !trend.labels.length) return null;
    return {
        labels: trend.labels,
        datasets: trend.series.map((s) => ({
            label: t(`production_tasks.cat_${s.category}`),
            data: s.data,
            role: categoryRole[s.category] ?? 'neutral',
        })),
    };
});

const blankTaskRow = () => ({ name: '', category: 'other', house_number: '', unit: 'm²', unit_price: 0, planned_quantity: null, weightage: 0, status: 'open', notes: '' });

// Bulk add — a grid of rows.
const bulkOpen = ref(false);
const bulkForm = useForm({ tasks: [blankTaskRow()] });
function openBulk() { bulkForm.tasks = [blankTaskRow()]; bulkForm.clearErrors(); bulkOpen.value = true; }
function addBulkRow() { bulkForm.tasks.push(blankTaskRow()); }
function removeBulkRow(i) { if (bulkForm.tasks.length > 1) bulkForm.tasks.splice(i, 1); }
function duplicateBulkRow(i) { bulkForm.tasks.splice(i + 1, 0, { ...bulkForm.tasks[i] }); }

// Auto-distribute 100% weightage equally, remainder onto the last row so the
// sum is exactly 100.
function autoWeightage() {
    const n = bulkForm.tasks.length;
    if (!n) return;
    const base = Math.floor((100 / n) * 100) / 100;
    bulkForm.tasks.forEach((r, idx) => {
        r.weightage = idx === n - 1 ? Math.round((100 - base * (n - 1)) * 100) / 100 : base;
    });
}
const weightageTotal = computed(() => Math.round(bulkForm.tasks.reduce((s, r) => s + (Number(r.weightage) || 0), 0) * 100) / 100);

// Map a pasted category cell (label or key, any case) to a valid category key.
function normalizeCategory(v) {
    const s = String(v ?? '').trim().toLowerCase();
    if (props.taskCategories.includes(s)) return s;
    const byLabel = props.taskCategories.find((c) => t(`production_tasks.cat_${c}`).toLowerCase() === s);
    return byLabel ?? 'other';
}

// The grid's visual column order — paste-from-Excel fills fields in this order.
const BULK_COLS = ['category', 'unit', 'name', 'unit_price', 'planned_quantity', 'weightage', 'house_number'];

// Paste tab-separated rows from a spreadsheet. Starting at (rowIndex, colKey),
// fill across and down, appending rows as needed. Single-cell pastes fall
// through to the default input behaviour.
function onBulkPaste(e, rowIndex, colKey) {
    const text = e.clipboardData?.getData('text/plain') ?? '';
    if (!/\t|\n/.test(text)) return; // ordinary single value → let the input handle it
    e.preventDefault();
    const lines = text.replace(/\r/g, '').split('\n').filter((l, idx, arr) => l !== '' || idx < arr.length - 1);
    const startCol = BULK_COLS.indexOf(colKey);
    lines.forEach((line, r) => {
        const target = rowIndex + r;
        if (!bulkForm.tasks[target]) bulkForm.tasks.push(blankTaskRow());
        line.split('\t').forEach((cell, c) => {
            const key = BULK_COLS[startCol + c];
            if (!key) return;
            const val = cell.trim();
            if (key === 'category') bulkForm.tasks[target].category = normalizeCategory(val);
            else if (['unit_price', 'planned_quantity', 'weightage'].includes(key)) bulkForm.tasks[target][key] = val === '' ? (key === 'unit_price' || key === 'weightage' ? 0 : null) : Number(val.replace(',', '.'));
            else bulkForm.tasks[target][key] = val;
        });
    });
}

// Enter adds a row after the current one; Delete/Backspace on a fully blank row
// removes it (keyboard-only bulk entry).
function onBulkRowKeydown(e, i) {
    if (e.key === 'Enter') {
        e.preventDefault();
        bulkForm.tasks.splice(i + 1, 0, blankTaskRow());
    } else if ((e.key === 'Delete' || e.key === 'Backspace')) {
        const r = bulkForm.tasks[i];
        const blank = !r.name && !r.unit_price && r.planned_quantity == null && !r.house_number && !r.weightage;
        if (blank && bulkForm.tasks.length > 1) { e.preventDefault(); removeBulkRow(i); }
    }
}
function submitBulk() {
    bulkForm.post(`/projects/${props.project.id}/tasks`, { preserveScroll: true, onSuccess: () => (bulkOpen.value = false) });
}

// Edit a single task.
const taskEditOpen = ref(false);
const taskForm = useForm(blankTaskRow());
const editingTaskId = ref(null);
function openTaskEdit(t) {
    editingTaskId.value = t.id;
    Object.assign(taskForm, { name: t.name, category: t.category, house_number: t.house_number ?? '', unit: t.unit ?? 'm²', unit_price: t.unit_price, planned_quantity: t.planned_quantity, weightage: t.weightage, status: t.status, notes: t.notes ?? '' });
    taskForm.clearErrors();
    taskEditOpen.value = true;
}
function submitTaskEdit() {
    taskForm.put(`/projects/${props.project.id}/tasks/${editingTaskId.value}`, { preserveScroll: true, onSuccess: () => (taskEditOpen.value = false) });
}
function deleteTask(t) {
    askDelete(t.name, () => router.delete(`/projects/${props.project.id}/tasks/${t.id}`, { preserveScroll: true }));
}

// Expand task rows (a parent + a child can each be open independently).
const expandedIds = ref(new Set());
function isExpanded(t) { return expandedIds.value.has(t.id); }
function toggleExpand(t) {
    const next = new Set(expandedIds.value);
    next.has(t.id) ? next.delete(t.id) : next.add(t.id);
    expandedIds.value = next;
}

// Add a sub-task under a top-level task (one level deep only).
const subtaskOpen = ref(false);
const subtaskParent = ref(null);
const subtaskForm = useForm(blankTaskRow());
function openSubtask(parent) {
    subtaskParent.value = parent;
    Object.assign(subtaskForm, blankTaskRow());
    subtaskForm.clearErrors();
    subtaskOpen.value = true;
}
function submitSubtask() {
    subtaskForm.transform((d) => ({ parent_task_id: subtaskParent.value?.id, tasks: [d] }))
        .post(`/projects/${props.project.id}/tasks`, { preserveScroll: true, onSuccess: () => (subtaskOpen.value = false) });
}

// ── Log work (daily production entry) ───────────────────────────────────────
const logOpen = ref(false);
const logTask = ref(null);
const presentWorkers = ref([]);
const loadingWorkers = ref(false);
const logForm = useForm({ date: new Date().toISOString().slice(0, 10), quantity: null, employee_ids: [], notes: '', photo: null });

async function fetchPresentWorkers() {
    loadingWorkers.value = true;
    logForm.employee_ids = [];
    try {
        const res = await fetch(`/projects/${props.project.id}/present-workers?date=${logForm.date}`, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        presentWorkers.value = data.workers ?? [];
    } catch { presentWorkers.value = []; }
    loadingWorkers.value = false;
}

function openLog(t) {
    logTask.value = t;
    // Explicit reset — form.reset() left the previous quantity in place.
    logForm.date = new Date().toISOString().slice(0, 10);
    logForm.quantity = null;
    logForm.employee_ids = [];
    logForm.notes = '';
    logForm.photo = null;
    logForm.clearErrors();
    presentWorkers.value = [];
    logOpen.value = true;
    fetchPresentWorkers();
}

const splitPreview = computed(() => {
    const n = logForm.employee_ids.length;
    const q = Number(logForm.quantity);
    if (!n || !q) return null;
    return (q / n).toFixed(2);
});

function submitLog() {
    logForm
        .transform((d) => ({ ...d, quantity: d.quantity ?? '' }))
        .post(`/projects/${props.project.id}/tasks/${logTask.value.id}/progress`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => (logOpen.value = false),
        });
}

function deleteBatch(t, batch) {
    askDelete(`${batch.date} · ${batch.quantity}`, () =>
        router.delete(`/projects/${props.project.id}/tasks/${t.id}/progress/${batch.batch_id}`, { preserveScroll: true }));
}

// Prefill a bulk-add row from a template (one-shot copy — no lasting link).
function applyTemplate(row, templateId) {
    const tpl = props.taskTemplates.find((t) => t.id === Number(templateId));
    if (!tpl) return;
    row.name = tpl.name;
    row.category = tpl.category;
    row.unit = tpl.unit ?? row.unit;
    row.unit_price = tpl.unit_price ?? 0;
    if (tpl.planned_quantity != null) row.planned_quantity = tpl.planned_quantity;
    if (tpl.weightage != null) row.weightage = tpl.weightage;
    row._tpl = ''; // reset the picker
}

// Templates catalogue (company-scoped) — managed inline.
const templatesOpen = ref(false);
const templateForm = useForm({ name: '', category: 'other', unit: 'm²', unit_price: 0, planned_quantity: null, weightage: 0, description: '', active: true });
const editingTemplateId = ref(null);
function newTemplate() {
    editingTemplateId.value = null;
    Object.assign(templateForm, { name: '', category: 'other', unit: 'm²', unit_price: 0, planned_quantity: null, weightage: 0, description: '', active: true });
    templateForm.clearErrors();
}
function openTemplateEdit(t) {
    editingTemplateId.value = t.id;
    Object.assign(templateForm, { name: t.name, category: t.category, unit: t.unit ?? 'm²', unit_price: t.unit_price ?? 0, planned_quantity: t.planned_quantity, weightage: t.weightage ?? 0, description: t.description ?? '', active: true });
    templateForm.clearErrors();
}
function saveTemplate() {
    const done = () => newTemplate();
    if (editingTemplateId.value) {
        templateForm.put(`/task-templates/${editingTemplateId.value}`, { preserveScroll: true, onSuccess: done });
    } else {
        templateForm.post('/task-templates', { preserveScroll: true, onSuccess: done });
    }
}
function deleteTemplate(t) {
    askDelete(t.name, () => router.delete(`/task-templates/${t.id}`, { preserveScroll: true }));
}

// immutable notes
const noteForm = useForm({ type: 'internal', body: '', noted_at: null });
function addNote() { noteForm.post(`/projects/${props.project.id}/remarks`, { preserveScroll: true, onSuccess: () => noteForm.reset() }); }

const noteStatus = { internal: 'neutral', client_call: 'info', client_email: 'accent', meeting: 'warn', message: 'neutral' };
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
                            <div class="flex items-center justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.revenue_basis') }}</dt>
                                <dd><VBadge :status="profitability.revenue_basis === 'not_configured' ? 'neutral' : 'info'">{{ $t(`profitability.basis_${profitability.revenue_basis}`) }}</VBadge></dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.total_revenue') }}</dt>
                                <dd class="tabular-nums font-medium">{{ profitability.revenue_basis === 'not_configured' ? '—' : eur(profitability.revenue) }}</dd>
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
                    <!-- No revenue basis configured: show cost-to-date, NOT a fake −100 % loss. -->
                    <div v-if="profitability.revenue_basis === 'not_configured'"
                        class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface-sunken px-4 py-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-muted">{{ $t('profitability.gross_profit') }}</p>
                            <p class="text-sm font-medium text-ink-soft">{{ $t('profitability.no_revenue_configured') }}</p>
                        </div>
                        <div class="text-end">
                            <p class="text-xs uppercase tracking-wide text-muted">{{ $t('profitability.cost_to_date') }}</p>
                            <p class="tabular-nums text-xl font-semibold text-ink-soft">{{ eur(profitability.cost) }}</p>
                        </div>
                    </div>
                    <div v-else class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface-sunken px-4 py-3">
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
                        <div class="flex justify-between gap-3 py-2">
                            <dt><span class="text-xs text-muted">{{ $t('projects.section_location') }}</span></dt>
                            <dd class="text-end text-sm">
                                <a v-if="project.latitude != null && project.longitude != null"
                                    :href="`https://www.google.com/maps?q=${project.latitude},${project.longitude}`"
                                    target="_blank" rel="noopener" class="text-accent hover:underline">
                                    {{ project.latitude }}, {{ project.longitude }} · {{ project.geofence_radius }} m
                                </a>
                                <span v-else class="text-muted">{{ $t('projects.location_not_set') }}</span>
                            </dd>
                        </div>
                    </dl>
                    <p v-if="project.description" class="mt-3 text-sm text-ink-soft">{{ project.description }}</p>
                </VCard>
                <VCard title-key="projects.section_contacts">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div v-for="c in contactCards" :key="c.k" class="rounded-md border border-line p-3">
                            <Bilingual :k="c.k" class="text-xs text-muted" />
                            <template v-if="c.c">
                                <p class="mt-0.5 text-sm font-medium">
                                    <Link v-if="c.c.employee_id" :href="`/employees/${c.c.employee_id}`" class="text-accent hover:underline">{{ c.c.name }}</Link>
                                    <span v-else>{{ c.c.name }}</span>
                                </p>
                                <p v-if="c.c.designation" class="text-xs text-muted">{{ c.c.designation }}</p>
                                <a v-if="c.c.phone" :href="`tel:${c.c.phone}`" class="text-xs text-accent hover:underline">{{ c.c.phone }}</a>
                            </template>
                            <p v-else class="mt-0.5 text-sm text-muted">—</p>
                        </div>
                    </div>
                </VCard>

                <!-- Client-side people who handle THIS project (supervisor /
                     engineer / PM / other). Simple CRUD, gated by projects.edit. -->
                <VCard title-key="projects.section_client_contacts" class="lg:col-span-2" :padded="false">
                    <template v-if="can.edit" #header>
                        <VButton variant="secondary" size="sm" icon="plus" @click="openContact()">
                            <Bilingual k="projects.contact_add" inline />
                        </VButton>
                    </template>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-max text-sm">
                            <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                                <tr>
                                    <th class="px-4 py-2 text-start"><Bilingual k="projects.contact_name" inline /></th>
                                    <th class="px-4 py-2 text-start"><Bilingual k="projects.contact_role" inline /></th>
                                    <th class="px-4 py-2 text-start"><Bilingual k="projects.contact_phone" inline /></th>
                                    <th class="px-4 py-2 text-start"><Bilingual k="projects.contact_email" inline /></th>
                                    <th class="px-4 py-2 text-start"><Bilingual k="projects.contact_notes" inline /></th>
                                    <th v-if="can.edit" class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in projectContacts" :key="c.id" class="border-b border-line">
                                    <td class="px-4 py-2 font-medium">{{ c.name }}</td>
                                    <td class="px-4 py-2"><VBadge status="info">{{ $t(`projects.contact_role_${c.role}`) }}</VBadge></td>
                                    <td class="px-4 py-2">
                                        <a v-if="c.phone" :href="`tel:${c.phone}`" class="text-accent hover:underline">{{ c.phone }}</a>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="px-4 py-2">
                                        <a v-if="c.email" :href="`mailto:${c.email}`" class="text-accent hover:underline">{{ c.email }}</a>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="px-4 py-2 text-ink-soft">{{ c.notes || '—' }}</td>
                                    <td v-if="can.edit" class="whitespace-nowrap px-4 py-2 text-end">
                                        <VButton variant="ghost" size="sm" icon="edit" @click="openContact(c)" />
                                        <VButton variant="ghost" size="sm" icon="trash" @click="removeContact(c)" />
                                    </td>
                                </tr>
                                <tr v-if="projectContacts.length === 0">
                                    <td :colspan="can.edit ? 6 : 5" class="px-4 py-6 text-center text-sm text-muted">{{ $t('projects.contacts_empty') }}</td>
                                </tr>
                            </tbody>
                        </table>
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
                :documents="documents" :sets="documentSets" :field-defs="documentFieldDefs" :can="can" />

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
            <div v-else-if="tab === 'invoices'" class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div v-if="invoiceSummary" class="grid grid-cols-3 gap-3">
                        <div class="rounded-lg border border-line bg-surface-raised px-4 py-3">
                            <p class="text-[11px] uppercase tracking-wide text-muted">{{ $t('projects.total_invoiced') }}</p>
                            <p class="tabular-nums mt-0.5 text-lg font-semibold text-ink">{{ eur(invoiceSummary.invoiced) }}</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface-raised px-4 py-3">
                            <p class="text-[11px] uppercase tracking-wide text-muted">{{ $t('projects.total_paid') }}</p>
                            <p class="tabular-nums mt-0.5 text-lg font-semibold text-status-ok">{{ eur(invoiceSummary.paid) }}</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface-raised px-4 py-3">
                            <p class="text-[11px] uppercase tracking-wide text-muted">{{ $t('projects.pending_payment') }}</p>
                            <p class="tabular-nums mt-0.5 text-lg font-semibold" :class="invoiceSummary.pending > 0 ? 'text-status-warn' : 'text-ink'">{{ eur(invoiceSummary.pending) }}</p>
                        </div>
                    </div>
                    <VButton v-if="canCreateInvoice" icon="plus" class="ms-auto"
                        @click="router.get('/invoices', { preset_project: project.id })">
                        <Bilingual k="invoices.new" inline />
                    </VButton>
                </div>
                <VFinanceRows :rows="invoices" :can-view="canViewInvoices" empty-key="finance.no_invoices" />
            </div>

            <!-- Gastos — expenses booked against this project -->
            <div v-else-if="tab === 'expenses'" class="space-y-4">
                <VFinanceRows :rows="expenses" :can-view="canViewExpenses" empty-key="finance.no_expenses" />

                <!-- Category breakdown (split-aware) -->
                <div v-if="canViewExpenses && expenseBreakdown.length"
                    class="rounded-lg border border-line bg-surface-raised p-4 shadow-card">
                    <h3 class="mb-3 text-sm font-semibold text-ink">{{ $t('expenses.split_breakdown') }}</h3>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase text-muted">
                                <th class="px-2 py-2 text-start font-medium">{{ $t('expenses.split_category') }}</th>
                                <th class="px-2 py-2 text-end font-medium">{{ $t('expenses.total') }}</th>
                                <th class="px-2 py-2 text-end font-medium">{{ $t('expenses.split_pct_of') }}</th>
                                <th class="w-1/3 px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(b, i) in expenseBreakdown" :key="i" class="border-b border-line">
                                <td class="px-2 py-2 text-ink">{{ b.category }}</td>
                                <td class="tabular-nums px-2 py-2 text-end">{{ eur(b.total) }}</td>
                                <td class="tabular-nums px-2 py-2 text-end text-ink-soft">{{ b.pct }}%</td>
                                <td class="px-2 py-2">
                                    <div class="h-2 overflow-hidden rounded-full bg-surface-sunken">
                                        <div class="h-full rounded-full bg-accent" :style="{ width: `${Math.min(100, b.pct)}%` }" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

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

            <!-- Tareas — production tasks (internal planned-vs-actual) -->
            <div v-else-if="tab === 'tasks'" class="space-y-4">
                <div v-if="!canManageTasks.view" class="py-8 text-center text-sm text-muted">{{ $t('common.no_permission') }}</div>
                <template v-else-if="projectTasks">
                    <!-- Overall weighted progress -->
                    <VCard>
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium"><Bilingual k="production_tasks.overall" inline /></span>
                            <span class="tabular-nums font-semibold">{{ projectTasks.overall_progress }}%</span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-surface-sunken">
                            <div class="h-full rounded-full bg-accent transition-all" :style="{ width: Math.min(100, projectTasks.overall_progress) + '%' }" />
                        </div>
                        <p v-if="projectTasks.tasks.length && Math.round(projectTasks.weightage_sum) !== 100"
                            class="mt-2 text-xs text-status-warn">
                            {{ $t('production_tasks.weightage_warn', { sum: projectTasks.weightage_sum }) }}
                        </p>
                    </VCard>

                    <div class="flex justify-end gap-2">
                        <VButton v-if="canManageTasks.create" variant="secondary" icon="copy" @click="() => { newTemplate(); templatesOpen = true; }">
                            <Bilingual k="task_templates.manage" inline />
                        </VButton>
                        <VButton v-if="canManageTasks.create" icon="plus" @click="openBulk">
                            <Bilingual k="production_tasks.add" inline />
                        </VButton>
                    </div>

                    <VCard :padded="false">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                                    <tr>
                                        <th class="px-3 py-2 text-start"><Bilingual k="production_tasks.name" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="production_tasks.category" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="production_tasks.house" inline /></th>
                                        <th class="px-3 py-2 text-end"><Bilingual k="production_tasks.planned" inline /></th>
                                        <th class="px-3 py-2 text-end"><Bilingual k="production_tasks.done" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="production_tasks.progress" inline /></th>
                                        <th class="px-3 py-2 text-start"><Bilingual k="production_tasks.status" inline /></th>
                                        <th class="px-3 py-2 text-end"><Bilingual k="common.actions" inline /></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="t in projectTasks.tasks" :key="t.id">
                                        <!-- Top-level task -->
                                        <tr class="border-b border-line hover:bg-surface-hover" :class="{ 'border-b-0': isExpanded(t) }">
                                            <td class="px-3 py-2 font-medium">
                                                <button type="button" class="flex items-center gap-1.5 text-start hover:text-accent" @click="toggleExpand(t)">
                                                    <span class="text-muted transition-transform" :class="{ 'rotate-90': isExpanded(t) }">▸</span>
                                                    <span>{{ t.name }}</span>
                                                    <span v-if="t.children.length" class="rounded-full bg-accent-soft px-1.5 text-[10px] font-medium text-accent">{{ t.children.length }}</span>
                                                    <span v-else-if="t.batches.length" class="rounded-full bg-surface-sunken px-1.5 text-[10px] text-muted">{{ t.batches.length }}</span>
                                                </button>
                                            </td>
                                            <td class="px-3 py-2 text-ink-soft">{{ $t(`production_tasks.cat_${t.category}`) }}</td>
                                            <td class="px-3 py-2 text-ink-soft">{{ t.house_number ?? '—' }}</td>
                                            <td class="tabular-nums px-3 py-2 text-end">{{ t.planned_quantity }} {{ t.unit }}</td>
                                            <td class="tabular-nums px-3 py-2 text-end">{{ t.completed_quantity }}</td>
                                            <td class="px-3 py-2">
                                                <div class="flex items-center gap-2">
                                                    <div class="h-2 w-24 overflow-hidden rounded-full bg-surface-sunken">
                                                        <div class="h-full rounded-full" :class="taskHealth[t.health]" :style="{ width: t.progress + '%' }" />
                                                    </div>
                                                    <span class="tabular-nums text-xs">{{ t.progress }}%</span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2"><VBadge :status="taskStatusVariant[t.status]">{{ $t(`production_tasks.st_${t.status}`) }}</VBadge></td>
                                            <td class="px-3 py-2 text-end whitespace-nowrap">
                                                <VButton v-if="canManageTasks.create" variant="ghost" size="sm" icon="plus" :title="$t('production_tasks.add_subtask')" @click="openSubtask(t)" />
                                                <VButton v-if="canManageTasks.edit && t.children.length === 0" variant="ghost" size="sm" icon="attendance" :title="$t('task_progress.log')" @click="openLog(t)" />
                                                <VButton v-if="canManageTasks.edit" variant="ghost" size="sm" icon="edit" @click="openTaskEdit(t)" />
                                                <VButton v-if="canManageTasks.delete" variant="ghost" size="sm" icon="trash" @click="deleteTask(t)" />
                                            </td>
                                        </tr>

                                        <!-- Expanded parent WITH sub-tasks → indented child rows -->
                                        <template v-if="isExpanded(t) && t.children.length">
                                            <template v-for="c in t.children" :key="c.id">
                                                <tr class="border-b border-line bg-surface-sunken/30 hover:bg-surface-hover" :class="{ 'border-b-0': isExpanded(c) }">
                                                    <td class="px-3 py-2 font-medium">
                                                        <button type="button" class="flex items-center gap-1.5 ps-6 text-start hover:text-accent" @click="toggleExpand(c)">
                                                            <span class="text-muted">↳</span>
                                                            <span class="text-muted transition-transform" :class="{ 'rotate-90': isExpanded(c) }">▸</span>
                                                            <span>{{ c.name }}</span>
                                                            <span v-if="c.batches.length" class="rounded-full bg-surface-sunken px-1.5 text-[10px] text-muted">{{ c.batches.length }}</span>
                                                        </button>
                                                    </td>
                                                    <td class="px-3 py-2 text-ink-soft">{{ $t(`production_tasks.cat_${c.category}`) }}</td>
                                                    <td class="px-3 py-2 text-ink-soft">{{ c.house_number ?? '—' }}</td>
                                                    <td class="tabular-nums px-3 py-2 text-end">{{ c.planned_quantity }} {{ c.unit }}</td>
                                                    <td class="tabular-nums px-3 py-2 text-end">{{ c.completed_quantity }}</td>
                                                    <td class="px-3 py-2">
                                                        <div class="flex items-center gap-2">
                                                            <div class="h-2 w-24 overflow-hidden rounded-full bg-surface-sunken">
                                                                <div class="h-full rounded-full" :class="taskHealth[c.health]" :style="{ width: c.progress + '%' }" />
                                                            </div>
                                                            <span class="tabular-nums text-xs">{{ c.progress }}%</span>
                                                        </div>
                                                    </td>
                                                    <td class="px-3 py-2"><VBadge :status="taskStatusVariant[c.status]">{{ $t(`production_tasks.st_${c.status}`) }}</VBadge></td>
                                                    <td class="px-3 py-2 text-end whitespace-nowrap">
                                                        <VButton v-if="canManageTasks.edit" variant="ghost" size="sm" icon="attendance" :title="$t('task_progress.log')" @click="openLog(c)" />
                                                        <VButton v-if="canManageTasks.edit" variant="ghost" size="sm" icon="edit" @click="openTaskEdit(c)" />
                                                        <VButton v-if="canManageTasks.delete" variant="ghost" size="sm" icon="trash" @click="deleteTask(c)" />
                                                    </td>
                                                </tr>
                                                <tr v-if="isExpanded(c)" class="border-b border-line bg-surface-sunken/40">
                                                    <td colspan="8" class="px-3 py-2 ps-10">
                                                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted">{{ $t('task_progress.history') }}</p>
                                                        <div v-if="c.batches.length === 0" class="py-2 text-sm text-muted">{{ $t('task_progress.empty') }}</div>
                                                        <table v-else class="w-full text-sm">
                                                            <tbody>
                                                                <tr v-for="b in c.batches" :key="b.batch_id" class="border-b border-line/60 last:border-0">
                                                                    <td class="py-1.5 pe-3 tabular-nums text-ink-soft">{{ b.date }}</td>
                                                                    <td class="py-1.5 pe-3 tabular-nums font-medium">{{ b.quantity }} {{ c.unit }}</td>
                                                                    <td class="py-1.5 pe-3 text-ink-soft">{{ b.workers.join(', ') || '—' }}</td>
                                                                    <td class="py-1.5 pe-3 text-muted">{{ b.notes }}</td>
                                                                    <td class="py-1.5 pe-3">
                                                                        <a v-if="b.photo_id" :href="`/task-progress/${b.photo_id}/photo`" target="_blank" class="inline-flex items-center gap-1 text-accent hover:underline">
                                                                            <AppIcon name="camera" class="h-3.5 w-3.5" />{{ $t('task_progress.photo') }}
                                                                        </a>
                                                                    </td>
                                                                    <td class="py-1.5 text-end">
                                                                        <VButton v-if="canManageTasks.edit" variant="ghost" size="sm" icon="trash" @click="deleteBatch(c, b)" />
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </td>
                                                </tr>
                                            </template>
                                        </template>

                                        <!-- Expanded leaf (no sub-tasks) → its own daily-production history -->
                                        <tr v-else-if="isExpanded(t)" class="border-b border-line bg-surface-sunken/40">
                                            <td colspan="8" class="px-3 py-2">
                                                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted">{{ $t('task_progress.history') }}</p>
                                                <div v-if="t.batches.length === 0" class="py-2 text-sm text-muted">{{ $t('task_progress.empty') }}</div>
                                                <table v-else class="w-full text-sm">
                                                    <tbody>
                                                        <tr v-for="b in t.batches" :key="b.batch_id" class="border-b border-line/60 last:border-0">
                                                            <td class="py-1.5 pe-3 tabular-nums text-ink-soft">{{ b.date }}</td>
                                                            <td class="py-1.5 pe-3 tabular-nums font-medium">{{ b.quantity }} {{ t.unit }}</td>
                                                            <td class="py-1.5 pe-3 text-ink-soft">{{ b.workers.join(', ') || '—' }}</td>
                                                            <td class="py-1.5 pe-3 text-muted">{{ b.notes }}</td>
                                                            <td class="py-1.5 pe-3">
                                                                <a v-if="b.photo_id" :href="`/task-progress/${b.photo_id}/photo`" target="_blank" class="inline-flex items-center gap-1 text-accent hover:underline">
                                                                    <AppIcon name="camera" class="h-3.5 w-3.5" />{{ $t('task_progress.photo') }}
                                                                </a>
                                                            </td>
                                                            <td class="py-1.5 text-end">
                                                                <VButton v-if="canManageTasks.edit" variant="ghost" size="sm" icon="trash" @click="deleteBatch(t, b)" />
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr v-if="projectTasks.tasks.length === 0"><td colspan="8" class="px-3 py-6 text-center text-muted">{{ $t('production_tasks.empty') }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </VCard>
                    <p class="text-xs text-muted">{{ $t('production_tasks.internal_hint') }}</p>

                    <!-- Phase E — production reports -->
                    <template v-if="taskReport">
                        <!-- E1 · Planned vs actual + estimated finish -->
                        <VCard v-if="taskReport.planned_vs_actual.rows.length" :padded="false">
                            <div class="border-b border-line px-4 py-3">
                                <h3 class="text-sm font-semibold"><Bilingual k="task_report.planned_vs_actual" inline /></h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                                        <tr>
                                            <th class="px-3 py-2 text-start"><Bilingual k="production_tasks.name" inline /></th>
                                            <th class="px-3 py-2 text-end"><Bilingual k="task_report.planned" inline /></th>
                                            <th class="px-3 py-2 text-end"><Bilingual k="task_report.done" inline /></th>
                                            <th class="px-3 py-2 text-end"><Bilingual k="task_report.remaining" inline /></th>
                                            <th class="px-3 py-2 text-end"><Bilingual k="task_report.pct_done" inline /></th>
                                            <th class="px-3 py-2 text-start"><Bilingual k="task_report.est_finish" inline /></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in taskReport.planned_vs_actual.rows" :key="r.id" class="border-b border-line last:border-0">
                                            <td class="px-3 py-2 font-medium">{{ r.name }}</td>
                                            <td class="tabular-nums px-3 py-2 text-end">{{ r.planned }} {{ r.unit }}</td>
                                            <td class="tabular-nums px-3 py-2 text-end">{{ r.done }}</td>
                                            <td class="tabular-nums px-3 py-2 text-end">{{ r.remaining }}</td>
                                            <td class="tabular-nums px-3 py-2 text-end"><span :class="{ 'text-status-ok': r.health === 'ok', 'text-status-warn': r.health === 'warn', 'text-status-danger': r.health === 'danger' }">{{ r.progress }}%</span></td>
                                            <td class="px-3 py-2">
                                                <VBadge v-if="r.est_finish === 'done'" status="ok"><Bilingual k="task_report.finished" inline /></VBadge>
                                                <span v-else-if="r.est_finish" class="tabular-nums">{{ r.est_finish }}</span>
                                                <span v-else class="text-muted">—</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="border-t border-line-strong bg-surface-sunken/50 font-medium">
                                        <tr>
                                            <td class="px-3 py-2"><Bilingual k="common.total" inline /></td>
                                            <td class="tabular-nums px-3 py-2 text-end">{{ taskReport.planned_vs_actual.totals.planned }}</td>
                                            <td class="tabular-nums px-3 py-2 text-end">{{ taskReport.planned_vs_actual.totals.done }}</td>
                                            <td class="tabular-nums px-3 py-2 text-end">{{ taskReport.planned_vs_actual.totals.remaining }}</td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <p class="px-4 py-2 text-xs text-muted">{{ $t('task_report.est_hint') }}</p>
                        </VCard>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <!-- E2 · Per-worker productivity -->
                            <VCard v-if="taskReport.per_worker.length" :padded="false">
                                <div class="border-b border-line px-4 py-3">
                                    <h3 class="text-sm font-semibold"><Bilingual k="task_report.per_worker" inline /></h3>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                                            <tr>
                                                <th class="px-3 py-2 text-start"><Bilingual k="task_progress.workers" inline /></th>
                                                <th class="px-3 py-2 text-end"><Bilingual k="task_report.total_qty" inline /></th>
                                                <th class="px-3 py-2 text-end"><Bilingual k="task_report.last7" inline /></th>
                                                <th class="px-3 py-2 text-end"><Bilingual k="task_report.entries" inline /></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(w, i) in taskReport.per_worker" :key="i" class="border-b border-line last:border-0">
                                                <td class="px-3 py-2 font-medium">{{ w.employee }}</td>
                                                <td class="tabular-nums px-3 py-2 text-end">{{ w.total }}</td>
                                                <td class="tabular-nums px-3 py-2 text-end">{{ w.last7 }}</td>
                                                <td class="tabular-nums px-3 py-2 text-end">{{ w.entries }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </VCard>

                            <!-- E3 · Production trend -->
                            <VCard v-if="trendChart">
                                <h3 class="mb-3 text-sm font-semibold"><Bilingual k="task_report.trend" inline /></h3>
                                <VChart type="line" :labels="trendChart.labels" :datasets="trendChart.datasets" :height="220" />
                            </VCard>
                        </div>
                    </template>
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
        <!-- Add / edit a client-side contact -->
        <VModal :open="contactModalOpen" :title-key="contactEditing ? 'projects.contact_edit' : 'projects.contact_add'" @close="contactModalOpen = false">
            <form id="contact-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveContact">
                <FormField k="projects.contact_name" :error="contactForm.errors.name" required>
                    <VInput v-model="contactForm.name" :invalid="Boolean(contactForm.errors.name)" />
                </FormField>
                <FormField k="projects.contact_role" :error="contactForm.errors.role" required>
                    <VSelect v-model="contactForm.role">
                        <option v-for="r in contactRoles" :key="r" :value="r">{{ $t(`projects.contact_role_${r}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="projects.contact_phone" :error="contactForm.errors.phone">
                    <VInput v-model="contactForm.phone" type="tel" />
                </FormField>
                <FormField k="projects.contact_email" :error="contactForm.errors.email">
                    <VInput v-model="contactForm.email" type="email" :invalid="Boolean(contactForm.errors.email)" />
                </FormField>
                <FormField k="projects.contact_notes" :error="contactForm.errors.notes" class="sm:col-span-2">
                    <VTextarea v-model="contactForm.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="contactModalOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="contact-form" :loading="contactForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

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

        <!-- Bulk add production tasks -->
        <VModal :open="bulkOpen" title-key="production_tasks.add" size="xl" @close="bulkOpen = false">
            <div class="space-y-2">
                <div v-if="taskTemplates.length" class="flex items-center gap-2 rounded-md bg-surface-sunken px-3 py-2">
                    <span class="text-xs text-muted whitespace-nowrap">{{ $t('task_templates.prefill') }}</span>
                    <VSelect :model-value="''" class="max-w-xs" @update:model-value="(v) => { if (v) { bulkForm.tasks.push(blankTaskRow()); applyTemplate(bulkForm.tasks[bulkForm.tasks.length - 1], v); } }">
                        <option value="">{{ $t('task_templates.pick') }}</option>
                        <option v-for="tpl in taskTemplates" :key="tpl.id" :value="tpl.id">{{ tpl.name }}</option>
                    </VSelect>
                </div>
                <!-- Toolbar: auto-distribute weightage + a running total indicator -->
                <div class="flex flex-wrap items-center gap-3">
                    <VButton variant="secondary" size="sm" @click="autoWeightage">
                        <Bilingual k="production_tasks.auto_weightage" inline />
                    </VButton>
                    <span class="text-xs" :class="weightageTotal === 100 ? 'text-status-ok' : 'text-status-warn'">
                        {{ $t('production_tasks.weightage') }}: {{ weightageTotal }}% <span v-if="weightageTotal === 100">✅</span>
                    </span>
                    <span class="ms-auto text-[11px] text-muted">{{ $t('production_tasks.paste_hint') }}</span>
                </div>
                <!-- Column headers in the agreed order -->
                <div class="grid grid-cols-12 gap-2 px-0.5 text-[11px] font-medium uppercase tracking-wide text-muted">
                    <span class="col-span-2">{{ $t('production_tasks.category') }}</span>
                    <span class="col-span-1">{{ $t('production_tasks.unit') }}</span>
                    <span class="col-span-3">{{ $t('production_tasks.name') }}</span>
                    <span class="col-span-1">{{ $t('production_tasks.unit_price') }} €</span>
                    <span class="col-span-2">{{ $t('production_tasks.planned') }}</span>
                    <span class="col-span-1">{{ $t('production_tasks.weightage') }} %</span>
                    <span class="col-span-1">{{ $t('production_tasks.house') }}</span>
                    <span class="col-span-1"></span>
                </div>
                <div v-for="(row, i) in bulkForm.tasks" :key="i" class="grid grid-cols-12 items-start gap-2"
                    @keydown="onBulkRowKeydown($event, i)">
                    <VSelect v-model="row.category" class="col-span-2" @paste="onBulkPaste($event, i, 'category')">
                        <option v-for="c in taskCategories" :key="c" :value="c">{{ $t(`production_tasks.cat_${c}`) }}</option>
                    </VSelect>
                    <VInput v-model="row.unit" class="col-span-1" :placeholder="$t('production_tasks.ph_unit')" @paste="onBulkPaste($event, i, 'unit')" />
                    <VInput v-model="row.name" class="col-span-3" :placeholder="$t('production_tasks.ph_name')" @paste="onBulkPaste($event, i, 'name')" />
                    <VInput v-model="row.unit_price" type="number" step="0.01" min="0" class="col-span-1" :placeholder="$t('production_tasks.ph_unit_price')" @paste="onBulkPaste($event, i, 'unit_price')" />
                    <VInput v-model="row.planned_quantity" type="number" step="0.01" min="0" class="col-span-2" :placeholder="$t('production_tasks.ph_planned')" @paste="onBulkPaste($event, i, 'planned_quantity')" />
                    <VInput v-model="row.weightage" type="number" step="0.01" min="0" max="100" class="col-span-1" :placeholder="$t('production_tasks.ph_weightage')" @paste="onBulkPaste($event, i, 'weightage')" />
                    <VInput v-model="row.house_number" class="col-span-1" :placeholder="$t('production_tasks.ph_house')" @paste="onBulkPaste($event, i, 'house_number')" />
                    <div class="col-span-1 flex items-center gap-0.5">
                        <button type="button" class="rounded-sm p-1.5 text-muted hover:text-accent" :title="$t('production_tasks.duplicate_row')" @click="duplicateBulkRow(i)">
                            <AppIcon name="copy" class="h-3.5 w-3.5" />
                        </button>
                        <button type="button" class="rounded-sm p-1.5 text-muted hover:text-status-danger" :disabled="bulkForm.tasks.length === 1" @click="removeBulkRow(i)">
                            <AppIcon name="trash" class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
                <VButton variant="ghost" size="sm" icon="plus" @click="addBulkRow"><Bilingual k="production_tasks.add_row" inline /></VButton>
                <p class="text-xs text-muted">{{ $t('production_tasks.unit_price_hint') }}</p>
            </div>
            <template #footer>
                <VButton variant="ghost" @click="bulkOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="button" :loading="bulkForm.processing" @click="submitBulk"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <!-- Edit a single production task -->
        <VModal :open="taskEditOpen" title-key="production_tasks.edit" @close="taskEditOpen = false">
            <form id="task-edit-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitTaskEdit">
                <!-- 1. Category -->
                <FormField k="production_tasks.category" :error="taskForm.errors.category" required>
                    <VSelect v-model="taskForm.category"><option v-for="c in taskCategories" :key="c" :value="c">{{ $t(`production_tasks.cat_${c}`) }}</option></VSelect>
                </FormField>
                <!-- 2. Unit -->
                <FormField k="production_tasks.unit" :error="taskForm.errors.unit"><VInput v-model="taskForm.unit" :placeholder="$t('production_tasks.ph_unit')" /></FormField>
                <!-- 3. Task description -->
                <FormField k="production_tasks.name" class="sm:col-span-2" :error="taskForm.errors.name" required><VInput v-model="taskForm.name" :placeholder="$t('production_tasks.ph_name')" /></FormField>
                <!-- 4. Price per unit -->
                <FormField k="production_tasks.unit_price" :error="taskForm.errors.unit_price">
                    <div class="relative">
                        <VInput v-model="taskForm.unit_price" type="number" step="0.01" min="0" class="pe-7" :placeholder="$t('production_tasks.ph_unit_price')" />
                        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-sm text-muted">€</span>
                    </div>
                    <p class="mt-1 text-xs text-muted">{{ $t('production_tasks.unit_price_hint') }}</p>
                </FormField>
                <!-- 5. Total measurement / planned quantity -->
                <FormField k="production_tasks.planned" :error="taskForm.errors.planned_quantity" required><VInput v-model="taskForm.planned_quantity" type="number" step="0.01" min="0" :placeholder="$t('production_tasks.ph_planned')" /></FormField>
                <!-- 6. Weightage % -->
                <FormField k="production_tasks.weightage" :error="taskForm.errors.weightage">
                    <VInput v-model="taskForm.weightage" type="number" step="0.01" min="0" max="100" :placeholder="$t('production_tasks.ph_weightage')" />
                    <p class="mt-1 text-xs text-muted">{{ $t('production_tasks.weightage_hint') }}</p>
                </FormField>
                <!-- 7. House number (optional) -->
                <FormField k="production_tasks.house" :error="taskForm.errors.house_number"><VInput v-model="taskForm.house_number" :placeholder="$t('production_tasks.ph_house')" /></FormField>
                <FormField k="production_tasks.status" :error="taskForm.errors.status" required>
                    <VSelect v-model="taskForm.status"><option v-for="s in taskStatuses" :key="s" :value="s">{{ $t(`production_tasks.st_${s}`) }}</option></VSelect>
                </FormField>
                <FormField k="production_tasks.notes" class="sm:col-span-2" :error="taskForm.errors.notes"><VTextarea v-model="taskForm.notes" :rows="2" /></FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="taskEditOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="task-edit-form" :loading="taskForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <!-- Add a sub-task under a top-level task -->
        <VModal :open="subtaskOpen" title-key="production_tasks.add_subtask" @close="subtaskOpen = false">
            <p v-if="subtaskParent" class="mb-3 text-xs text-muted">{{ $t('production_tasks.subtask_of') }}: <span class="font-medium text-ink">{{ subtaskParent.name }}</span></p>
            <form id="subtask-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitSubtask">
                <FormField k="production_tasks.category" :error="subtaskForm.errors['tasks.0.category']" required>
                    <VSelect v-model="subtaskForm.category"><option v-for="c in taskCategories" :key="c" :value="c">{{ $t(`production_tasks.cat_${c}`) }}</option></VSelect>
                </FormField>
                <FormField k="production_tasks.unit" :error="subtaskForm.errors['tasks.0.unit']"><VInput v-model="subtaskForm.unit" :placeholder="$t('production_tasks.ph_unit')" /></FormField>
                <FormField k="production_tasks.name" class="sm:col-span-2" :error="subtaskForm.errors['tasks.0.name']" required><VInput v-model="subtaskForm.name" :placeholder="$t('production_tasks.ph_name')" /></FormField>
                <FormField k="production_tasks.unit_price" :error="subtaskForm.errors['tasks.0.unit_price']">
                    <div class="relative">
                        <VInput v-model="subtaskForm.unit_price" type="number" step="0.01" min="0" class="pe-7" :placeholder="$t('production_tasks.ph_unit_price')" />
                        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-sm text-muted">€</span>
                    </div>
                    <p class="mt-1 text-xs text-muted">{{ $t('production_tasks.unit_price_hint') }}</p>
                </FormField>
                <FormField k="production_tasks.planned" :error="subtaskForm.errors['tasks.0.planned_quantity']" required><VInput v-model="subtaskForm.planned_quantity" type="number" step="0.01" min="0" :placeholder="$t('production_tasks.ph_planned')" /></FormField>
                <FormField k="production_tasks.weightage" :error="subtaskForm.errors['tasks.0.weightage']">
                    <VInput v-model="subtaskForm.weightage" type="number" step="0.01" min="0" max="100" :placeholder="$t('production_tasks.ph_weightage')" />
                </FormField>
                <FormField k="production_tasks.house" :error="subtaskForm.errors['tasks.0.house_number']"><VInput v-model="subtaskForm.house_number" :placeholder="$t('production_tasks.ph_house')" /></FormField>
                <FormField k="production_tasks.status" :error="subtaskForm.errors['tasks.0.status']" required>
                    <VSelect v-model="subtaskForm.status"><option v-for="s in taskStatuses" :key="s" :value="s">{{ $t(`production_tasks.st_${s}`) }}</option></VSelect>
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="subtaskOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="subtask-form" :loading="subtaskForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <!-- Log daily production (multi-worker split) -->
        <VModal :open="logOpen" title-key="task_progress.log" @close="logOpen = false">
            <div v-if="logTask" class="space-y-4">
                <p class="text-sm text-ink-soft">{{ logTask.name }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="task_progress.date" :error="logForm.errors.date" required>
                        <VInput v-model="logForm.date" type="date" @change="fetchPresentWorkers" />
                    </FormField>
                    <FormField k="task_progress.quantity" :error="logForm.errors.quantity" required>
                        <VInput v-model="logForm.quantity" type="number" step="0.01" min="0" :placeholder="logTask.unit" />
                    </FormField>
                </div>

                <div>
                    <p class="mb-1.5 text-sm font-medium"><Bilingual k="task_progress.workers" inline /></p>
                    <p class="mb-2 text-xs text-muted">{{ $t('task_progress.workers_hint') }}</p>
                    <div v-if="loadingWorkers" class="py-3 text-sm text-muted">{{ $t('common.loading') }}</div>
                    <div v-else-if="presentWorkers.length === 0" class="rounded-md bg-status-warn-soft px-3 py-2 text-sm text-status-warn">{{ $t('task_progress.none_present') }}</div>
                    <div v-else class="grid gap-1.5 sm:grid-cols-2">
                        <label v-for="w in presentWorkers" :key="w.id" class="flex items-center gap-2 rounded-md border border-line px-2.5 py-1.5 text-sm hover:bg-surface-hover">
                            <input type="checkbox" :value="w.id" v-model="logForm.employee_ids" class="accent-accent" />
                            <span>{{ w.name }}</span>
                            <span v-if="w.designation" class="text-xs text-muted">· {{ w.designation }}</span>
                        </label>
                    </div>
                    <p v-if="logForm.errors.employee_ids" class="mt-1 text-xs text-status-danger">{{ logForm.errors.employee_ids }}</p>
                    <p v-if="splitPreview" class="mt-2 text-xs text-ink-soft">
                        {{ $t('task_progress.split_preview', { qty: splitPreview, unit: logTask.unit ?? '', n: logForm.employee_ids.length }) }}
                    </p>
                </div>

                <FormField k="task_progress.photo" :error="logForm.errors.photo">
                    <input type="file" accept="image/*,.pdf" capture="environment" class="block w-full text-sm text-ink-soft file:mr-3 file:rounded-md file:border-0 file:bg-surface-sunken file:px-3 file:py-1.5 file:text-sm" @change="(e) => (logForm.photo = e.target.files[0] ?? null)" />
                </FormField>
                <FormField k="task_progress.notes" :error="logForm.errors.notes">
                    <VTextarea v-model="logForm.notes" :rows="2" />
                </FormField>
            </div>
            <template #footer>
                <VButton variant="ghost" @click="logOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="button" :loading="logForm.processing" :disabled="!logForm.employee_ids.length" @click="submitLog"><Bilingual k="task_progress.save_btn" inline /></VButton>
            </template>
        </VModal>

        <!-- Task templates catalogue -->
        <VModal :open="templatesOpen" title-key="task_templates.manage" size="lg" @close="templatesOpen = false">
            <div class="space-y-4">
                <form class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-2" @submit.prevent="saveTemplate">
                    <p class="sm:col-span-2 text-xs font-medium text-ink-soft">
                        {{ editingTemplateId ? $t('task_templates.edit') : $t('task_templates.new') }}
                    </p>
                    <FormField k="task_templates.name" :error="templateForm.errors.name" required><VInput v-model="templateForm.name" /></FormField>
                    <FormField k="task_templates.category" :error="templateForm.errors.category" required>
                        <VSelect v-model="templateForm.category"><option v-for="c in taskCategories" :key="c" :value="c">{{ $t(`production_tasks.cat_${c}`) }}</option></VSelect>
                    </FormField>
                    <FormField k="task_templates.unit" :error="templateForm.errors.unit"><VInput v-model="templateForm.unit" /></FormField>
                    <FormField k="task_templates.unit_price" :error="templateForm.errors.unit_price"><VInput v-model="templateForm.unit_price" type="number" step="0.01" min="0" /></FormField>
                    <FormField k="task_templates.planned" :error="templateForm.errors.planned_quantity"><VInput v-model="templateForm.planned_quantity" type="number" step="0.01" min="0" /></FormField>
                    <FormField k="task_templates.weightage" :error="templateForm.errors.weightage"><VInput v-model="templateForm.weightage" type="number" step="0.01" min="0" max="100" /></FormField>
                    <FormField k="task_templates.description" class="sm:col-span-2" :error="templateForm.errors.description"><VInput v-model="templateForm.description" /></FormField>
                    <div class="sm:col-span-2 flex justify-end gap-2">
                        <VButton v-if="editingTemplateId" variant="ghost" size="sm" type="button" @click="newTemplate"><Bilingual k="common.cancel" inline /></VButton>
                        <VButton size="sm" type="submit" :loading="templateForm.processing">
                            <Bilingual :k="editingTemplateId ? 'common.save' : 'task_templates.add'" inline />
                        </VButton>
                    </div>
                </form>

                <div v-if="taskTemplates.length" class="divide-y divide-line rounded-lg border border-line">
                    <div v-for="tpl in taskTemplates" :key="tpl.id" class="flex items-center justify-between px-3 py-2 text-sm">
                        <div>
                            <span class="font-medium">{{ tpl.name }}</span>
                            <span class="ml-2 text-xs text-muted">{{ $t(`production_tasks.cat_${tpl.category}`) }}<template v-if="tpl.unit"> · {{ tpl.unit }}</template></span>
                        </div>
                        <div class="flex items-center gap-1">
                            <VButton variant="ghost" size="sm" icon="edit" @click="openTemplateEdit(tpl)" />
                            <VButton v-if="canManageTasks.delete" variant="ghost" size="sm" icon="trash" @click="deleteTemplate(tpl)" />
                        </div>
                    </div>
                </div>
                <p v-else class="text-center text-sm text-muted">{{ $t('task_templates.empty') }}</p>
            </div>
            <template #footer>
                <VButton variant="ghost" @click="templatesOpen = false"><Bilingual k="common.close" inline /></VButton>
            </template>
        </VModal>

        <ProjectFormModal :open="showEdit" :project="project" :clients="clients" :employees="employeeOptions" :vat-options="vatOptions" @close="showEdit = false" />
        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
