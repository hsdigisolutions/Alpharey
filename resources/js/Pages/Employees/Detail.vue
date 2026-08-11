<script setup>
/**
 * Screen 06 — Employee Detail. Six tabs; Phase 2 delivers Información,
 * Documentos, Notas, Llamadas. Asistencia + Nómina are placeholders
 * until Phases 4/6. Edit opens the shared modal (never a separate page).
 */
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AttendanceModal from '@/Components/Attendance/AttendanceModal.vue';
import EmployeeFormModal from '@/Components/Employees/EmployeeFormModal.vue';
import WageRateFormModal from '@/Components/Employees/WageRateFormModal.vue';
import DocumentsPanel from '@/Components/Documents/DocumentsPanel.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VTimeline from '@/Components/ui/VTimeline.vue';
import VTimelineItem from '@/Components/ui/VTimelineItem.vue';

const props = defineProps({
    employee: { type: Object, required: true },
    documents: { type: Array, required: true },
    documentSets: { type: Object, required: true },
    notes: { type: Array, required: true },
    calls: { type: Array, required: true },
    payroll: { type: Array, default: () => [] },
    wageHistory: { type: Array, default: () => [] },
    attendanceTab: { type: Object, default: null },
    attendanceEditing: { type: Object, default: null },
    attendanceProjects: { type: Array, default: () => [] },
    attendanceEmployee: { type: Object, default: null },
    canManageAttendance: { type: Boolean, default: false },
    appAccess: { type: Object, default: () => ({ email: null, active: false }) },
    canSeeWages: { type: Boolean, default: false },
    can: { type: Object, required: true },
});

const tab = ref('info');
const showEdit = ref(false);
const showDelete = ref(false);

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }

// --- Mobile app access (Worker PWA) ---
const showAppAccess = ref(false);

const appAccessForm = useForm({ email: '', password: '' });

function submitAppAccess() {
    appAccessForm.post(`/employees/${props.employee.id}/app-access`, {
        preserveScroll: true,
        onSuccess: () => {
            showAppAccess.value = false;
            appAccessForm.reset();
        },
    });
}

function revokeAppAccess() {
    askDelete(props.appAccess.email ?? props.employee.full_name,
        () => router.delete(`/employees/${props.employee.id}/app-access`, { preserveScroll: true }));
}

// Authoritative from the server (payroll.view || employees.edit), not a guess.
const canSeeWages = props.canSeeWages;

function eur(value) {
    return `${Number(value ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

// --- Historial de Salario (wage history) ---
const showWageModal = ref(false);

// The rate in force today — the "previous rate" a new one supersedes.
const currentRate = computed(() => props.wageHistory.find((r) => r.is_current) ?? null);

function wageDate(value) {
    if (!value) return t('wage_rates.today');
    return new Date(`${value}T00:00:00`).toLocaleDateString('es-ES', {
        day: '2-digit', month: 'short', year: 'numeric',
    });
}

function wageTypeLabel(value) {
    return value ? t(`employees.wage_${value}`) : '—';
}

function deleteRate(rate) {
    askDelete(`${eur(rate.rate)} · ${wageDate(rate.effective_from)}`,
        () => router.delete(`/employees/${props.employee.id}/wage-rates/${rate.id}`, { preserveScroll: true }));
}

// --- Calls timeline helpers ---
function callDateTime(value) {
    if (!value) return '';
    const d = new Date(value.replace(' ', 'T'));
    return d.toLocaleString('es-ES', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function followUpOverdue(value) {
    if (!value) return false;
    return new Date(`${value}T00:00:00`) < new Date(new Date().toDateString());
}

// --- Asistencia tab ---
const showAttModal = ref(false);
const attNewDate = ref(null);
// Monday-first weekday headers, localised (Lun–Dom / Mon–Sun).
const attWeekdays = computed(() => [
    t('weekdays.mon'), t('weekdays.tue'), t('weekdays.wed'), t('weekdays.thu'),
    t('weekdays.fri'), t('weekdays.sat'), t('weekdays.sun'),
]);

const attStatusStyle = {
    late: 'bg-status-warn-soft text-status-warn',
    early_leave: 'bg-status-warn-soft text-status-warn',
    absent: 'bg-status-danger-soft text-status-danger',
    leave: 'bg-status-info-soft text-status-info',
};
const attDayTypeStyle = {
    full: 'bg-status-ok-soft text-status-ok',
    half: 'bg-status-warn-soft text-status-warn',
    hourly: 'bg-status-info-soft text-status-info',
    per_meter: 'bg-accent-soft text-accent',
};

// Calendar cells, Monday-first, with leading blanks for alignment.
const attCalendar = computed(() => {
    if (!props.attendanceTab) return [];
    const [y, m] = props.attendanceTab.month.split('-').map(Number);
    const lead = (new Date(y, m - 1, 1).getDay() + 6) % 7; // Mon=0
    const cells = [];
    for (let i = 0; i < lead; i++) cells.push({ day: null });
    for (let d = 1; d <= props.attendanceTab.days_in_month; d++) cells.push({ day: d });
    return cells;
});

function attCellClass(day) {
    const cell = props.attendanceTab.grid[day];
    const wknd = props.attendanceTab.weekend[day];
    if (!cell) return wknd ? 'bg-surface-sunken/50 text-faint' : 'hover:bg-surface-sunken text-muted';
    // An auto-generated absence shows a lighter red than a manual one.
    if (cell.status === 'absent' && cell.is_auto) return 'bg-status-danger-soft/40 text-status-danger/70';
    if (cell.status !== 'present') return attStatusStyle[cell.status] ?? attDayTypeStyle.full;
    if (wknd) return 'bg-accent-soft text-accent'; // weekend work (purple/coral)
    return attDayTypeStyle[cell.day_type] ?? attDayTypeStyle.full;
}

function attCellMarker(day) {
    const cell = props.attendanceTab.grid[day];
    if (!cell) return '';
    // Same codes as the worker PWA + Screen 11 (client request): PF/PH/A/L/WE.
    if (props.attendanceTab.weekend[day] && cell.status === 'present') return t('worker.cal_weekend'); // WE
    if (cell.status === 'absent') return t('worker.cal_absent'); // A
    if (cell.status === 'leave') return t('worker.cal_leave');   // L
    switch (cell.day_type) {
        case 'full': return t('worker.cal_full');   // PF
        case 'half': return t('worker.cal_half');   // PH
        case 'per_meter': return cell.quantity ?? '·';
        default: return cell.hours;
    }
}

function attMonthNav(delta) {
    const [y, m] = props.attendanceTab.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const month = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(`/employees/${props.employee.id}`, { att_month: month },
        { only: ['attendanceTab'], preserveState: true, preserveScroll: true });
}

function openAttCell(day) {
    const cell = props.attendanceTab.grid[day];
    // A live-computed absence (no real row) has id === null — open "new entry".
    if (cell && cell.id) {
        router.get(`/employees/${props.employee.id}`,
            { att_month: props.attendanceTab.month, att_edit: cell.id },
            { only: ['attendanceEditing'], preserveState: true, preserveScroll: true });
    } else {
        attNewDate.value = `${props.attendanceTab.month}-${String(day).padStart(2, '0')}`;
        showAttModal.value = true;
    }
}

function openAttNew() {
    attNewDate.value = `${props.attendanceTab.month}-01`;
    showAttModal.value = true;
}

function closeAttModal() {
    showAttModal.value = false;
    attNewDate.value = null;
    // Drop the editing payload and refresh the grid (covers a just-saved change).
    router.get(`/employees/${props.employee.id}`, { att_month: props.attendanceTab?.month },
        { only: ['attendanceTab', 'attendanceEditing'], preserveState: true, preserveScroll: true });
}

// Open the modal in edit mode once the server hands back the day's payload.
watch(() => props.attendanceEditing, (v) => { if (v) showAttModal.value = true; });

const infoRows = [
    { k: 'employees.code', v: props.employee.employee_code },
    { k: 'employees.nif', v: props.employee.nif },
    { k: 'auth.email', v: props.employee.email },
    { k: 'employees.mobile', v: props.employee.mobile },
    { k: 'employees.phone', v: props.employee.phone },
    { k: 'employees.city', v: props.employee.city },
    { k: 'employees.address', v: props.employee.address },
    { k: 'employees.department', v: props.employee.department },
    { k: 'employees.designation', v: props.employee.designation },
    { k: 'employees.team_leader', v: props.employee.team_leader },
    { k: 'employees.joining_date', v: props.employee.joining_date },
    { k: 'employees.leaving_date', v: props.employee.leaving_date },
];

const wageRows = [
    { k: 'employees.wage_rate', v: props.employee.wage_rate },
    { k: 'employees.base_salary', v: props.employee.base_salary },
    { k: 'employees.daily_wage', v: props.employee.daily_wage },
    { k: 'employees.per_meter_rate', v: props.employee.per_meter_rate },
    { k: 'employees.commission', v: props.employee.commission_percent },
    { k: 'employees.iban', v: props.employee.iban },
    { k: 'employees.bank_name', v: props.employee.bank_name },
];

const tabs = [
    { key: 'info', labelKey: 'employees.tab_info' },
    { key: 'docs', labelKey: 'employees.tab_docs', count: props.documents.filter((d) => d.has_file || d.has_flag).length },
    { key: 'attendance', labelKey: 'employees.tab_attendance' },
    { key: 'payroll', labelKey: 'employees.tab_payroll' },
    { key: 'notes', labelKey: 'employees.tab_notes', count: props.notes.length },
    { key: 'calls', labelKey: 'employees.tab_calls', count: props.calls.length },
];

const noteStatus = { general: 'neutral', reminder: 'info', issue: 'warn', call: 'accent' };

/* notes */
const noteForm = useForm({ type: 'general', body: '', noted_at: null });
function submitNote() {
    noteForm.post(`/employees/${props.employee.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}
function deleteNote(id) {
    askDelete('', () => router.delete(`/employees/${props.employee.id}/notes/${id}`, { preserveScroll: true }));
}

/* calls */
const callForm = useForm({ called_at: null, remarks: '', follow_up_date: null });
function submitCall() {
    callForm.post(`/employees/${props.employee.id}/calls`, {
        preserveScroll: true,
        onSuccess: () => callForm.reset(),
    });
}

function destroy() {
    askDelete(props.employee.full_name,
        () => router.delete(`/employees/${props.employee.id}`));
}
</script>

<template>
    <Head :title="employee.full_name" />

    <AppLayout>
        <!-- Header -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <VAvatar :name="employee.full_name" size="lg" />
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ employee.full_name }}</h1>
                <p class="tabular-nums text-sm text-muted">
                    {{ employee.employee_code }} · {{ employee.company }} · {{ employee.designation ?? '—' }}
                </p>
            </div>
            <VBadge :status="employee.active ? 'ok' : 'neutral'">
                <Bilingual :k="employee.active ? 'employees.active' : 'employees.inactive'" inline />
            </VBadge>
            <VButton v-if="can.edit" variant="secondary" icon="edit" @click="showEdit = true">
                <Bilingual k="employees.edit" inline />
            </VButton>
        </div>

        <VTabs v-model="tab" :tabs="tabs" />

        <div class="mt-5">
            <!-- Información -->
            <div v-if="tab === 'info'" class="grid gap-5 lg:grid-cols-2">
                <VCard title-key="employees.section_personal">
                    <dl class="divide-y divide-line">
                        <div v-for="row in infoRows" :key="row.k" class="flex justify-between gap-4 py-2">
                            <dt><Bilingual :k="row.k" class="text-xs text-muted" /></dt>
                            <dd class="text-end text-sm">{{ row.v ?? '—' }}</dd>
                        </div>
                    </dl>
                </VCard>
                <VCard v-if="canSeeWages" title-key="employees.section_wage">
                    <dl class="divide-y divide-line">
                        <div v-for="row in wageRows" :key="row.k" class="flex justify-between gap-4 py-2">
                            <dt><Bilingual :k="row.k" class="text-xs text-muted" /></dt>
                            <dd class="tabular-nums text-end text-sm">{{ row.v ?? '—' }}</dd>
                        </div>
                    </dl>
                    <div v-if="can.delete" class="mt-4 border-t border-line pt-4">
                        <VButton variant="danger" size="sm" icon="trash" @click="destroy">
                            <Bilingual k="employees.delete_title" inline />
                        </VButton>
                    </div>
                </VCard>

                <!-- Mobile app access: the login this worker uses to check in
                     on site. Most employees never need one. -->
                <VCard v-if="can.edit" title-key="worker_access.title">
                    <p class="mb-3 text-xs text-muted"><Bilingual k="worker_access.hint" /></p>

                    <div v-if="appAccess.email" class="space-y-3">
                        <div class="flex items-center justify-between gap-3 rounded-md bg-surface-sunken px-3 py-2">
                            <span class="min-w-0 truncate text-sm">{{ appAccess.email }}</span>
                            <VBadge :status="appAccess.active ? 'ok' : 'neutral'">
                                <Bilingual :k="appAccess.active ? 'worker_access.active' : 'worker_access.inactive'" inline />
                            </VBadge>
                        </div>
                        <div class="flex gap-2">
                            <VButton variant="secondary" size="sm" @click="showAppAccess = true">
                                <Bilingual k="worker_access.reset" inline />
                            </VButton>
                            <VButton variant="danger" size="sm" @click="revokeAppAccess">
                                <Bilingual k="worker_access.revoke" inline />
                            </VButton>
                        </div>
                    </div>

                    <VButton v-else size="sm" icon="plus" @click="showAppAccess = true">
                        <Bilingual k="worker_access.grant" inline />
                    </VButton>
                </VCard>

                <!-- Historial de Salario — the effective-dated wage timeline -->
                <VCard v-if="canSeeWages" class="lg:col-span-2">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="text-[15px] font-semibold"><Bilingual k="wage_rates.section_title" /></h3>
                        <VButton v-if="can.manageWages" size="sm" icon="plus" @click="showWageModal = true">
                            <Bilingual k="wage_rates.new" inline />
                        </VButton>
                    </div>

                    <VEmptyState v-if="!wageHistory.length" icon="euro" title-key="wage_rates.none" />

                    <ul v-else class="space-y-2">
                        <li v-for="rate in wageHistory" :key="rate.id"
                            class="flex items-center gap-3 rounded-md border px-3 py-2.5"
                            :class="rate.is_current
                                ? 'border-accent border-s-2 bg-accent-soft'
                                : 'border-line bg-surface-sunken'">
                            <span class="h-2 w-2 shrink-0 rounded-full"
                                :class="rate.is_current ? 'bg-accent' : 'bg-faint'" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm">
                                    {{ wageDate(rate.effective_from) }} → {{ wageDate(rate.effective_to) }}
                                </p>
                                <p class="text-xs text-muted">{{ wageTypeLabel(rate.wage_type) }}</p>
                            </div>
                            <span class="tabular-nums text-sm font-medium">{{ eur(rate.rate) }}</span>
                            <VBadge v-if="rate.is_current" status="ok">
                                <Bilingual k="wage_rates.current_badge" inline />
                            </VBadge>
                            <button v-if="can.manageWages && !rate.is_current" type="button"
                                class="rounded-md p-1 text-muted hover:bg-surface-hover hover:text-status-danger"
                                :aria-label="t('common.delete')" @click="deleteRate(rate)">
                                <AppIcon name="trash" class="h-4 w-4" />
                            </button>
                        </li>
                    </ul>
                </VCard>
            </div>

            <!-- Documentos -->
            <DocumentsPanel v-else-if="tab === 'docs'"
                entity-type="employee" :entity-id="employee.id"
                :documents="documents" :sets="documentSets" :can="can" />

            <!-- Asistencia — per-employee month calendar + summary -->
            <div v-else-if="tab === 'attendance'">
                <VCard v-if="!attendanceTab">
                    <VEmptyState icon="attendance" title-key="attendance.no_access" />
                </VCard>
                <template v-else>
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <VButton variant="secondary" size="sm" icon="chevron-left" @click="attMonthNav(-1)" />
                            <span class="min-w-32 text-center text-sm font-semibold tabular-nums">{{ attendanceTab.month }}</span>
                            <VButton variant="secondary" size="sm" icon="chevron-right" @click="attMonthNav(1)" />
                        </div>
                        <VButton v-if="canManageAttendance" size="sm" icon="plus" @click="openAttNew">
                            <Bilingual k="attendance.new" inline />
                        </VButton>
                    </div>

                    <VCard>
                        <!-- Weekday header -->
                        <div class="mb-1 grid grid-cols-7 gap-1 text-center text-[11px] font-medium uppercase text-muted">
                            <span v-for="(w, i) in attWeekdays" :key="i">{{ w }}</span>
                        </div>
                        <!-- Calendar grid -->
                        <div class="grid grid-cols-7 gap-1">
                            <template v-for="(c, i) in attCalendar" :key="i">
                                <span v-if="c.day === null" />
                                <button v-else type="button"
                                    class="relative flex aspect-square min-h-14 flex-col items-center justify-center rounded-md text-xs transition-colors"
                                    :class="attCellClass(c.day)"
                                    :title="attendanceTab.grid[c.day]?.project ?? ''"
                                    @click="openAttCell(c.day)">
                                    <span class="absolute start-1 top-0.5 text-[9px] font-medium opacity-70">{{ c.day }}</span>
                                    <span class="tabular-nums text-sm font-semibold">{{ attCellMarker(c.day) }}</span>
                                    <span v-if="attendanceTab.grid[c.day]?.project"
                                        class="absolute bottom-0.5 start-1 max-w-[85%] truncate text-[8px] opacity-70">{{ attendanceTab.grid[c.day].project.slice(0, 10) }}</span>
                                    <span v-if="canSeeWages && attendanceTab.grid[c.day]?.total"
                                        class="tabular-nums absolute bottom-0.5 end-1 text-[9px] font-semibold">{{ Math.round(attendanceTab.grid[c.day].total) }}€</span>
                                </button>
                            </template>
                        </div>
                        <!-- Legend -->
                        <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-muted">
                            <span><span class="inline-block h-2 w-2 rounded-full bg-status-ok"></span> {{ t('worker.cal_full') }} · <Bilingual k="attendance.day_type_full" inline /></span>
                            <span><span class="inline-block h-2 w-2 rounded-full bg-status-warn"></span> {{ t('worker.cal_half') }} · <Bilingual k="attendance.day_type_half" inline /></span>
                            <span><span class="inline-block h-2 w-2 rounded-full bg-status-info"></span> <Bilingual k="attendance.day_type_hourly" inline /></span>
                            <span><span class="inline-block h-2 w-2 rounded-full bg-accent"></span> {{ t('worker.cal_weekend') }} · <Bilingual k="attendance.weekend" inline /></span>
                            <span><span class="inline-block h-2 w-2 rounded-full bg-status-danger"></span> {{ t('worker.cal_absent') }} · <Bilingual k="attendance.status_absent" inline /></span>
                            <span><span class="inline-block h-2 w-2 rounded-full bg-status-info"></span> {{ t('worker.cal_leave') }} · <Bilingual k="attendance.status_leave" inline /></span>
                        </div>
                    </VCard>

                    <!-- Monthly summary -->
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <div class="rounded-lg border border-line bg-surface-raised p-3">
                            <p class="text-xs text-muted"><Bilingual k="attendance.sum_present" /></p>
                            <p class="tabular-nums mt-1 text-lg font-semibold">{{ attendanceTab.summary.present }}</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface-raised p-3">
                            <p class="text-xs text-muted"><Bilingual k="attendance.sum_half_days" /></p>
                            <p class="tabular-nums mt-1 text-lg font-semibold">{{ attendanceTab.summary.half_days }}</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface-raised p-3">
                            <p class="text-xs text-muted"><Bilingual k="attendance.sum_hours" /></p>
                            <p class="tabular-nums mt-1 text-lg font-semibold">{{ attendanceTab.summary.hours }}h</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface-raised p-3">
                            <p class="text-xs text-muted"><Bilingual k="attendance.sum_overtime" /></p>
                            <p class="tabular-nums mt-1 text-lg font-semibold">{{ attendanceTab.summary.overtime }}</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface-raised p-3">
                            <p class="text-xs text-muted"><Bilingual k="attendance.sum_absences" /></p>
                            <p class="tabular-nums mt-1 text-lg font-semibold">
                                {{ attendanceTab.summary.absences }}
                                <span v-if="attendanceTab.summary.auto_absences" class="text-xs font-normal text-muted">({{ attendanceTab.summary.auto_absences }} {{ $t('attendance.auto') }})</span>
                            </p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface-raised p-3">
                            <p class="text-xs text-muted"><Bilingual k="attendance.sum_leaves" /></p>
                            <p class="tabular-nums mt-1 text-lg font-semibold">{{ attendanceTab.summary.leaves }}</p>
                        </div>
                        <div v-if="canSeeWages" class="rounded-lg border border-accent/40 bg-accent-soft p-3">
                            <p class="text-xs text-accent"><Bilingual k="attendance.sum_total_wage" /></p>
                            <p class="tabular-nums mt-1 text-lg font-semibold text-accent">{{ eur(attendanceTab.summary.total_wage) }}</p>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Nómina — the employee's payroll history (pay data, gated) -->
            <VCard v-else-if="tab === 'payroll'">
                <p v-if="!canSeeWages" class="py-8 text-center text-sm text-muted">
                    <Bilingual k="employees.no_wage_permission" class="items-center" />
                </p>
                <p v-else-if="payroll.length === 0" class="py-8 text-center text-sm text-muted">
                    <Bilingual k="employees.no_payroll" class="items-center" />
                </p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase text-muted">
                                <th class="px-2 py-2 text-start font-medium">{{ $t('payroll.month') }}</th>
                                <th class="tabular-nums px-2 py-2 text-end font-medium">{{ $t('payroll.gross') }}</th>
                                <th class="tabular-nums px-2 py-2 text-end font-medium">{{ $t('payroll.net') }}</th>
                                <th class="px-2 py-2 text-start font-medium">{{ $t('payroll.status') }}</th>
                                <th class="px-2 py-2 text-start font-medium">{{ $t('payroll.paid_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in payroll" :key="row.id" class="border-b border-line">
                                <td class="px-2 py-2 text-ink">{{ row.month }}</td>
                                <td class="tabular-nums px-2 py-2 text-end text-ink-soft">{{ eur(row.gross_pay) }}</td>
                                <td class="tabular-nums px-2 py-2 text-end text-ink">{{ eur(row.net_amount) }}</td>
                                <td class="px-2 py-2">
                                    <VBadge :status="row.status === 'paid' ? 'ok' : 'warn'">{{ row.status }}</VBadge>
                                </td>
                                <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.paid_at ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </VCard>

            <!-- Notas -->
            <div v-else-if="tab === 'notes'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard>
                    <VTimeline v-if="notes.length">
                        <VTimelineItem v-for="note in notes" :key="note.id"
                            :time="note.noted_at" :author="note.author"
                            :type-label="$t(`employees.note_${note.type}`)"
                            :type-status="noteStatus[note.type]">
                            {{ note.body }}
                            <template v-if="can.edit" #attachment>
                                <button type="button" class="text-xs text-status-danger hover:underline"
                                    @click="deleteNote(note.id)">
                                    <Bilingual k="documents.delete" inline />
                                </button>
                            </template>
                        </VTimelineItem>
                    </VTimeline>
                    <VEmptyState v-else icon="file" />
                </VCard>
                <VCard v-if="can.edit" title-key="employees.add_note">
                    <form class="space-y-3" @submit.prevent="submitNote">
                        <FormField k="employees.note_type">
                            <VSelect v-model="noteForm.type">
                                <option v-for="t in ['general', 'reminder', 'issue', 'call']" :key="t" :value="t">
                                    {{ $t(`employees.note_${t}`) }}
                                </option>
                            </VSelect>
                        </FormField>
                        <FormField k="employees.notes" :error="noteForm.errors.body" required>
                            <VTextarea v-model="noteForm.body" :rows="3" />
                        </FormField>
                        <VButton type="submit" class="w-full" :loading="noteForm.processing">
                            <Bilingual k="common.save" inline />
                        </VButton>
                    </form>
                </VCard>
            </div>

            <!-- Llamadas -->
            <div v-else-if="tab === 'calls'" class="grid gap-5 lg:grid-cols-[1fr_340px]">
                <!-- Call log timeline: a connected rail of call cards -->
                <div v-if="calls.length" class="relative ps-2">
                    <span class="absolute inset-y-3 start-[19px] w-px bg-line" aria-hidden="true" />
                    <ul class="space-y-3">
                        <li v-for="call in calls" :key="call.id" class="relative flex gap-3">
                            <span class="relative z-10 mt-3 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-accent-soft bg-accent-soft text-accent ring-4 ring-surface">
                                <AppIcon name="calls" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1 rounded-lg border border-line bg-surface-raised p-3.5 shadow-card">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-sm font-semibold text-ink">{{ call.called_by ?? '—' }}</span>
                                    <time class="tabular-nums shrink-0 text-xs text-muted">{{ callDateTime(call.called_at) }}</time>
                                </div>
                                <p v-if="call.remarks" class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-ink-soft">{{ call.remarks }}</p>
                                <p v-else class="mt-1.5 text-sm italic text-muted"><Bilingual k="employees.no_remarks" /></p>
                                <div v-if="call.follow_up_date"
                                    class="mt-2.5 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="followUpOverdue(call.follow_up_date)
                                        ? 'bg-status-danger-soft text-status-danger'
                                        : 'bg-status-warn-soft text-status-warn'">
                                    <AppIcon name="calendar" class="h-3.5 w-3.5" />
                                    <Bilingual k="employees.follow_up" inline />
                                    <span class="tabular-nums">· {{ call.follow_up_date }}</span>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
                <VCard v-else><VEmptyState icon="calls" title-key="employees.no_calls" /></VCard>

                <!-- Log-a-call composer -->
                <VCard class="h-fit">
                    <div class="mb-4 flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-md bg-accent-soft text-accent">
                            <AppIcon name="calls" class="h-4 w-4" />
                        </span>
                        <h3 class="text-[15px] font-semibold"><Bilingual k="employees.add_call" /></h3>
                    </div>
                    <form class="space-y-3" @submit.prevent="submitCall">
                        <FormField k="employees.remarks" :error="callForm.errors.remarks" required>
                            <VTextarea v-model="callForm.remarks" :rows="4" />
                        </FormField>
                        <FormField k="employees.follow_up" :error="callForm.errors.follow_up_date">
                            <VDateInput v-model="callForm.follow_up_date" />
                        </FormField>
                        <VButton type="submit" icon="calls" class="w-full" :loading="callForm.processing">
                            <Bilingual k="employees.add_call" inline />
                        </VButton>
                    </form>
                </VCard>
            </div>
        </div>

        <EmployeeFormModal :open="showEdit" :employee="employee" :can-see-wages="canSeeWages" @close="showEdit = false" />

        <WageRateFormModal v-if="canSeeWages" :open="showWageModal" :employee-id="employee.id"
            :current-type="employee.wage_type" :current-rate="currentRate" @close="showWageModal = false" />

        <AttendanceModal v-if="attendanceEmployee"
            :open="showAttModal"
            :record="attendanceEditing"
            :preset-employee="employee.id"
            :preset-date="attNewDate"
            :employees="[attendanceEmployee]"
            :projects="attendanceProjects"
            :can-see-wage="canSeeWages"
            @close="closeAttModal" />

        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />

        <!-- Grant / reset mobile app access -->
        <VModal :open="showAppAccess"
            :title-key="appAccess.email ? 'worker_access.reset' : 'worker_access.grant'"
            size="sm" @close="showAppAccess = false">
            <form id="app-access-form" class="space-y-4" @submit.prevent="submitAppAccess">
                <p class="text-xs text-muted"><Bilingual k="worker_access.modal_hint" /></p>

                <FormField k="auth.email" :error="appAccessForm.errors.email" required>
                    <VInput v-model="appAccessForm.email" type="email" autocomplete="off" />
                </FormField>

                <FormField k="auth.password" :error="appAccessForm.errors.password" required>
                    <VInput v-model="appAccessForm.password" type="text" autocomplete="new-password" />
                </FormField>

                <p class="text-xs text-muted"><Bilingual k="worker_access.password_hint" /></p>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showAppAccess = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="app-access-form" :loading="appAccessForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
