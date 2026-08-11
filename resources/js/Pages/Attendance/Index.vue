<script setup>
/**
 * Screen 11 — Attendance calendar grid. Rows = employees, columns = days
 * of the selected month. Each cell: status dot + hours + project. Click a
 * cell to edit that day in a modal. Monthly summary below the grid.
 */
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { t } from '@/translate';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import AttendanceModal from '@/Components/Attendance/AttendanceModal.vue';
import BulkAttendanceModal from '@/Components/Attendance/BulkAttendanceModal.vue';
import WeekendOfferModal from '@/Components/Attendance/WeekendOfferModal.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';

const props = defineProps({
    month: { type: String, required: true },
    daysInMonth: { type: Number, required: true },
    employees: { type: Array, required: true },
    grid: { type: Object, required: true },
    summary: { type: Object, required: true },
    projects: { type: Array, required: true },
    projectAssignments: { type: Object, default: () => ({}) },
    canSeeWage: { type: Boolean, default: false },
    editing: { type: Object, default: null },
    can: { type: Object, required: true },
});

const page = usePage();

const days = computed(() => Array.from({ length: props.daysInMonth }, (_, i) => i + 1));

// Cell colors per spec: green present · amber late/early · red absent · blue leave
const cellStyle = {
    present: 'bg-status-ok-soft text-status-ok',
    late: 'bg-status-warn-soft text-status-warn',
    early_leave: 'bg-status-warn-soft text-status-warn',
    absent: 'bg-status-danger-soft text-status-danger',
    leave: 'bg-status-info-soft text-status-info',
};

// Colour a worked cell by DAY TYPE: full=green, half=amber, hourly=blue,
// per_meter=coral. Non-present statuses keep their status colour.
const dayTypeStyle = {
    full: 'bg-status-ok-soft text-status-ok',
    half: 'bg-status-warn-soft text-status-warn',
    hourly: 'bg-status-info-soft text-status-info',
    per_meter: 'bg-accent-soft text-accent',
};

function cellClass(cell) {
    if (!cell) return '';
    if (cell.status === 'absent' && cell.is_auto) return 'bg-status-danger-soft/40 text-status-danger/70';
    if (cell.status !== 'present') return cellStyle[cell.status] ?? cellStyle.present;
    if (cell.is_weekend) return 'bg-accent-soft text-accent'; // voluntary weekend work
    return dayTypeStyle[cell.day_type] ?? cellStyle.present;
}

// Marker uses the SAME codes as the worker PWA (single source, client request):
// WE weekend · PF full · PH half · A absent · L leave · hours/metres otherwise.
function cellContent(cell) {
    if (!cell) return '';
    if (cell.status === 'absent') return t('worker.cal_absent');   // A
    if (cell.status === 'leave') return t('worker.cal_leave');     // L
    if (cell.is_weekend) return t('worker.cal_weekend');           // WE
    switch (cell.day_type) {
        case 'full': return t('worker.cal_full');   // PF
        case 'half': return t('worker.cal_half');   // PH
        case 'per_meter': return cell.quantity ?? '·';
        default: return cell.hours;
    }
}

function changeMonth(delta) {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    router.get('/attendance', { month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` },
        { preserveScroll: true, preserveState: true });
}

function isWeekend(day) {
    const [y, m] = props.month.split('-').map(Number);
    const dow = new Date(y, m - 1, day).getDay();
    return dow === 0 || dow === 6;
}

// Locale-aware weekday abbreviation for a day column (Lun–Dom / Mon–Sun).
const weekdayKeys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
function weekdayKey(day) {
    const [y, m] = props.month.split('-').map(Number);
    return weekdayKeys[new Date(y, m - 1, day).getDay()];
}

/* --- bulk modal --- */
const showBulkModal = ref(false);

function openBulk() {
    if (!ensureCompanySelected()) return;
    showBulkModal.value = true;
}

/* --- weekend work offer modal --- */
const showOfferModal = ref(false);

function openOffer() {
    if (!ensureCompanySelected()) return;
    showOfferModal.value = true;
}

/* --- edit modal --- */
const showModal = ref(false);
const modalRecord = ref(null);
const preset = ref({ employee: null, date: null });

function openCreate() {
    // Attendance is logged against the acting company; pick one first.
    if (!ensureCompanySelected()) return;
    modalRecord.value = null;
    preset.value = {};
    showModal.value = true;
}

function openCell(employeeId, day) {
    if (!props.can.edit && !props.can.create) return;
    const cell = props.grid[employeeId]?.[day];
    const date = `${props.month}-${String(day).padStart(2, '0')}`;

    // A live-computed absence (no real row) has id === null — open "new entry"
    // for that day so an admin can record what actually happened.
    if (cell && cell.id) {
        // Fetch the full record via partial reload, then open
        router.get('/attendance', { month: props.month, edit: cell.id }, {
            preserveScroll: true, preserveState: true, only: ['editing'],
            onSuccess: () => { modalRecord.value = props.editing; preset.value = {}; showModal.value = true; },
        });
    } else {
        modalRecord.value = null;
        preset.value = { employee: employeeId, date };
        showModal.value = true;
    }
}

function exportMonth() {
    window.location.href = `/attendance/export?month=${props.month}`;
}

function downloadTemplate() {
    window.location.href = '/attendance/template';
}

const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Intl.DateTimeFormat(page.props.locale.primary === 'es' ? 'es-ES' : 'en-GB',
        { month: 'long', year: 'numeric' }).format(new Date(y, m - 1, 1));
});
</script>

<template>
    <Head :title="$t('attendance.title')" />
    <AppLayout>
        <VPageHeader k="attendance.title">
            <VButton v-if="can.export" variant="secondary" size="sm" icon="export" @click="exportMonth">Excel</VButton>
            <VButton v-if="can.create" variant="secondary" size="sm" @click="downloadTemplate">
                <Bilingual k="attendance.template" inline />
            </VButton>
            <VButton v-if="can.create" variant="secondary" icon="plus" @click="openBulk">
                <Bilingual k="attendance.bulk_new" inline />
            </VButton>
            <VButton v-if="can.edit" variant="secondary" size="sm" @click="openOffer">
                <Bilingual k="attendance.weekend_offer" inline />
            </VButton>
            <VButton v-if="can.create" icon="plus" @click="openCreate">
                <Bilingual k="attendance.new" inline />
            </VButton>
        </VPageHeader>

        <!-- Month navigation -->
        <div class="mb-4 flex items-center gap-3">
            <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="changeMonth(-1)">
                <AppIcon name="chevron-left" class="h-4 w-4" />
            </button>
            <span class="min-w-40 text-center text-sm font-semibold capitalize">{{ monthLabel }}</span>
            <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="changeMonth(1)">
                <AppIcon name="chevron-right" class="h-4 w-4" />
            </button>
        </div>

        <!-- Calendar grid -->
        <div class="overflow-x-auto rounded-lg border border-line bg-surface-raised shadow-card">
            <table class="min-w-max text-xs">
                <thead>
                    <tr class="border-b border-line bg-surface-sunken/60">
                        <th class="sticky start-0 z-10 min-w-40 bg-surface-sunken px-3 py-2 text-start">
                            <Bilingual k="attendance.employee" class="text-xs font-semibold text-ink-soft" />
                        </th>
                        <th v-for="day in days" :key="day" class="w-9 px-1 py-1.5 text-center font-semibold"
                            :class="isWeekend(day) ? 'text-faint' : 'text-ink-soft'">
                            <span class="block text-[9px] font-medium uppercase leading-none text-muted">{{ $t(`weekdays.${weekdayKey(day)}`) }}</span>
                            <span class="block leading-tight">{{ day }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="emp in employees" :key="emp.id" class="hover:bg-surface-hover/50">
                        <td class="sticky start-0 z-10 bg-surface-raised px-3 py-1.5">
                            <span class="flex items-center gap-1.5 truncate text-sm font-medium">
                                {{ emp.full_name }}
                                <VBadge v-if="emp.deployed" status="info" class="shrink-0">
                                    <Bilingual k="attendance.deployed" inline />
                                </VBadge>
                            </span>
                            <span class="block truncate text-[10px] text-muted">
                                <template v-if="emp.deployed">{{ emp.home_company }}</template>
                                <template v-else>{{ emp.designation ?? '—' }}</template>
                            </span>
                        </td>
                        <td v-for="day in days" :key="day" class="relative p-0.5 text-center">
                            <button type="button"
                                class="h-8 w-8 rounded-sm text-[10px] font-semibold transition-colors"
                                :class="grid[emp.id]?.[day]
                                    ? cellClass(grid[emp.id][day])
                                    : (isWeekend(day) ? 'bg-surface-sunken/40' : 'hover:bg-surface-sunken')"
                                :title="grid[emp.id]?.[day]?.project ?? ''"
                                @click="openCell(emp.id, day)">
                                {{ cellContent(grid[emp.id]?.[day]) }}
                            </button>
                            <!-- Worker left a voice/text note on this day -->
                            <AppIcon v-if="grid[emp.id]?.[day]?.has_voice_note" name="mic"
                                class="pointer-events-none absolute end-0.5 top-0.5 h-2.5 w-2.5 text-ink-soft"
                                :title="$t('attendance.voice_note')" />
                            <!-- Check-out location > 500 m from check-in location -->
                            <AppIcon v-if="grid[emp.id]?.[day]?.location_mismatch" name="alert"
                                class="pointer-events-none absolute start-0.5 top-0.5 h-2.5 w-2.5 text-status-warn"
                                :title="$t('attendance.location_mismatch')" />
                            <!-- Day-type auto-detection: 'A' = system-detected, '✎' = admin override -->
                            <span v-if="grid[emp.id]?.[day]?.is_auto_detected"
                                class="pointer-events-none absolute bottom-0 start-0.5 text-[7px] font-bold leading-none text-status-info"
                                :title="$t('attendance.auto_detected')">A</span>
                            <span v-else-if="grid[emp.id]?.[day]?.is_overridden"
                                class="pointer-events-none absolute bottom-0 start-0.5 text-[8px] leading-none text-ink-soft"
                                :title="$t('attendance.overridden')">✎</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Monthly summary -->
        <h2 class="mb-2 mt-6 text-[15px] font-semibold"><Bilingual k="attendance.summary" /></h2>
        <div class="overflow-x-auto rounded-lg border border-line bg-surface-raised shadow-card">
            <table class="w-full min-w-max text-sm">
                <thead>
                    <tr class="border-b border-line bg-surface-sunken/60 text-xs text-ink-soft">
                        <th class="px-3 py-2 text-start"><Bilingual k="attendance.employee" inline /></th>
                        <th class="px-3 py-2 text-end"><Bilingual k="attendance.days_present" inline /></th>
                        <th class="px-3 py-2 text-end"><Bilingual k="attendance.hours_worked" inline /></th>
                        <th class="px-3 py-2 text-end"><Bilingual k="attendance.overtime_hours" inline /></th>
                        <th class="px-3 py-2 text-end"><Bilingual k="attendance.absences" inline /></th>
                        <th v-if="canSeeWage" class="px-3 py-2 text-end"><Bilingual k="attendance.total_wage" inline /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="emp in employees" :key="emp.id" class="hover:bg-surface-hover">
                        <td class="px-3 py-2 font-medium">{{ emp.full_name }}</td>
                        <td class="tabular-nums px-3 py-2 text-end">{{ summary[emp.id]?.days_present ?? 0 }}</td>
                        <td class="tabular-nums px-3 py-2 text-end">{{ summary[emp.id]?.hours ?? 0 }}</td>
                        <td class="tabular-nums px-3 py-2 text-end">{{ summary[emp.id]?.overtime ?? 0 }}</td>
                        <td class="tabular-nums px-3 py-2 text-end">{{ summary[emp.id]?.absences ?? 0 }}</td>
                        <td v-if="canSeeWage" class="tabular-nums px-3 py-2 text-end">{{ summary[emp.id]?.total_wage ?? 0 }} €</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AttendanceModal :open="showModal" :record="modalRecord"
            :preset-employee="preset.employee" :preset-date="preset.date"
            :employees="employees" :projects="projects"
            :project-assignments="projectAssignments"
            :can-see-wage="canSeeWage"
            @close="showModal = false" />

        <BulkAttendanceModal :open="showBulkModal"
            :employees="employees" :projects="projects"
            :project-assignments="projectAssignments"
            :can-see-wage="canSeeWage"
            :month="month"
            @close="showBulkModal = false" />

        <WeekendOfferModal :open="showOfferModal"
            :employees="employees" :projects="projects"
            @close="showOfferModal = false" />
    </AppLayout>
</template>
