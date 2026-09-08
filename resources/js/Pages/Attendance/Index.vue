<script setup>
/**
 * Screen 11 — Attendance calendar grid. Rows = employees, columns = days
 * of the selected month. Each cell: status dot + hours + project. Click a
 * cell to edit that day in a modal. Monthly summary below the grid.
 */
import { computed, ref, watch } from 'vue';
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
import VSelect from '@/Components/ui/VSelect.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';

const props = defineProps({
    month: { type: String, required: true },
    empStatus: { type: String, default: 'active' },
    search: { type: String, default: '' },
    daysInMonth: { type: Number, required: true },
    employees: { type: Array, required: true },
    grid: { type: Object, required: true },
    summary: { type: Object, required: true },
    projects: { type: Array, required: true },
    formProjects: { type: Array, default: () => [] },
    projectAssignments: { type: Object, default: () => ({}) },
    projectPanel: { type: Object, default: null },
    canSeeWage: { type: Boolean, default: false },
    editing: { type: Object, default: null },
    can: { type: Object, required: true },
});

const page = usePage();

// --- Feature 1: project roster panel ---
const panelProject = ref(props.projectPanel?.project?.id ?? '');
const panelDate = ref(props.projectPanel?.date ?? new Date().toISOString().slice(0, 10));
function reloadPanel() {
    router.get('/attendance',
        { month: props.month, project: panelProject.value || undefined, panel_date: panelDate.value },
        { only: ['projectPanel'], preserveScroll: true, preserveState: true });
}
// Download the project roster panel (Excel or PDF) for the picked project + date.
function exportPanel(format) {
    const params = new URLSearchParams({
        project: props.projectPanel.project.id,
        panel_date: props.projectPanel.date,
        format,
    });
    window.location.href = `/attendance/panel-export?${params.toString()}`;
}
const panelStatusStyle = {
    present: 'text-status-ok', working: 'text-status-info', late: 'text-status-warn',
    early_leave: 'text-status-warn', absent: 'text-status-danger', leave: 'text-status-info',
};

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

// Distance-from-project dot: green on site, amber near, red off site.
const distanceDotStyle = { on_site: 'bg-status-ok', near_site: 'bg-status-warn', off_site: 'bg-status-danger' };
function distanceDotClass(band) {
    return distanceDotStyle[band] ?? '';
}
function distanceDotTitle(cell) {
    const label = { on_site: t('attendance.on_site'), near_site: t('attendance.near_site'), off_site: t('attendance.off_site') }[cell.distance_band] ?? '';
    if (cell.distance == null) return label;
    const d = cell.distance >= 1000 ? `${(cell.distance / 1000).toFixed(1)} km` : `${Math.round(cell.distance)} m`;
    return `${label} · ${d}`;
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

const empStatus = ref(props.empStatus ?? 'active');
const search = ref(props.search ?? '');

// Every grid reload carries the month + status + search together so switching
// one never drops the others.
function reloadGrid(overrides = {}) {
    router.get('/attendance',
        { month: props.month, emp_status: empStatus.value, search: search.value || undefined, ...overrides },
        { preserveScroll: true, preserveState: true });
}

function changeMonth(delta) {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    reloadGrid({ month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}
// Switch the Active / Inactive / Transferred / All employee-status filter.
function changeEmpStatus() {
    reloadGrid();
}

// Live search by employee name / code — same debounce as the Employees list.
let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reloadGrid(), 350);
});

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

/* The attendance modals select from ACTIVE projects only (Change 4). When
   editing a cell whose project has since completed, keep that project in the
   list so it still shows. The roster filter (above) keeps ALL projects. */
const modalProjects = computed(() => {
    const list = [...props.formProjects];
    const cur = modalRecord.value?.project_id;
    if (cur && !list.some((p) => Number(p.id) === Number(cur))) {
        const found = props.projects.find((p) => Number(p.id) === Number(cur));
        if (found) list.push(found);
    }
    return list;
});

function openCreate() {
    // Attendance is logged against the acting company; pick one first.
    if (!ensureCompanySelected()) return;
    modalRecord.value = null;
    preset.value = {};
    showModal.value = true;
}

function openCell(emp, day) {
    // A transferred-away worker's history is read-only at this company — the
    // cells still show their attendance, but never open for edit / new entry.
    if (emp.transferred_away) return;
    if (!props.can.edit && !props.can.create) return;
    const employeeId = emp.id;
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

            <!-- Search + employee status filter (Active / Inactive / Transferred / All) -->
            <div class="ms-auto flex flex-wrap items-center gap-2">
                <VSearchInput v-model="search" class="w-56" :placeholder="$tPair('attendance.search_placeholder')" />
                <VSelect v-model="empStatus" class="w-40" @update:model-value="changeEmpStatus">
                    <option value="active">{{ $t('attendance.emp_status_active') }}</option>
                    <option value="inactive">{{ $t('attendance.emp_status_inactive') }}</option>
                    <option value="transferred">{{ $t('attendance.emp_status_transferred') }}</option>
                    <option value="all">{{ $t('attendance.emp_status_all') }}</option>
                </VSelect>
                <VSelect v-model="panelProject" class="w-56" @update:model-value="reloadPanel">
                    <option value="">{{ $t('attendance.roster_pick_project') }}</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </VSelect>
                <input v-if="panelProject" v-model="panelDate" type="date"
                    class="rounded-md border border-line-strong bg-surface-sunken px-2 py-1.5 text-sm text-ink"
                    @change="reloadPanel" />
            </div>
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
                                <!-- A deployed-in worker is anonymised: the host
                                     never sees the home company's employee name. -->
                                {{ emp.deployed ? $t('attendance.deployed_worker') : emp.full_name }}
                                <VBadge v-if="emp.deployed" status="info" class="shrink-0">
                                    <Bilingual k="attendance.deployed" inline />
                                </VBadge>
                                <VBadge v-if="emp.transferred_away" status="warn" class="shrink-0">
                                    <Bilingual k="attendance.emp_status_transferred" inline />
                                </VBadge>
                                <VBadge v-else-if="emp.active === false" status="neutral" class="shrink-0">
                                    <Bilingual k="attendance.emp_status_inactive" inline />
                                </VBadge>
                            </span>
                            <span class="block truncate text-[10px] text-muted">
                                <template v-if="emp.deployed">{{ $t('attendance.deployed_from', { company: emp.home_company ?? '—' }) }}</template>
                                <template v-else>{{ emp.designation ?? '—' }}</template>
                            </span>
                        </td>
                        <td v-for="day in days" :key="day" class="relative p-0.5 text-center">
                            <button type="button"
                                class="h-8 w-8 rounded-sm text-[10px] font-semibold transition-colors"
                                :class="[grid[emp.id]?.[day]
                                    ? cellClass(grid[emp.id][day])
                                    : (isWeekend(day) ? 'bg-surface-sunken/40' : (emp.transferred_away ? '' : 'hover:bg-surface-sunken')),
                                    emp.transferred_away ? 'cursor-default' : '']"
                                :title="grid[emp.id]?.[day]?.project ?? ''"
                                @click="openCell(emp, day)">
                                {{ cellContent(grid[emp.id]?.[day]) }}
                            </button>
                            <!-- Worker left a note: a mic for a voice note, a plain
                                 note glyph for a text-only note. -->
                            <AppIcon v-if="grid[emp.id]?.[day]?.has_voice_note"
                                :name="grid[emp.id][day].voice_note_has_audio ? 'mic' : 'file'"
                                class="pointer-events-none absolute end-0.5 top-0.5 h-2.5 w-2.5 text-ink-soft"
                                :title="grid[emp.id][day].voice_note_has_audio ? $t('attendance.voice_note') : $t('attendance.note')" />
                            <!-- Check-out location > 500 m from check-in location -->
                            <AppIcon v-if="grid[emp.id]?.[day]?.location_mismatch" name="alert"
                                class="pointer-events-none absolute start-0.5 top-0.5 h-2.5 w-2.5 text-status-warn"
                                :title="$t('attendance.location_mismatch')" />
                            <!-- Day-type auto-detection: 'A' = system-detected, '✎' = admin override -->
                            <span v-if="grid[emp.id]?.[day]?.is_auto_detected && !grid[emp.id]?.[day]?.is_weekend"
                                class="pointer-events-none absolute bottom-0 start-0.5 text-[7px] font-bold leading-none text-status-info"
                                :title="$t('attendance.auto_detected')">A</span>
                            <span v-else-if="grid[emp.id]?.[day]?.is_overridden"
                                class="pointer-events-none absolute bottom-0 start-0.5 text-[8px] leading-none text-ink-soft"
                                :title="$t('attendance.overridden')">✎</span>
                            <!-- Distance-from-project band: on-site (green) / near (amber) / off-site (red) -->
                            <span v-if="grid[emp.id]?.[day]?.distance_band"
                                class="pointer-events-none absolute bottom-0.5 end-0.5 h-1.5 w-1.5 rounded-full"
                                :class="distanceDotClass(grid[emp.id][day].distance_band)"
                                :title="distanceDotTitle(grid[emp.id][day])" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Feature 1 — project roster panel for the picked project + date -->
        <div v-if="projectPanel" class="mt-6 rounded-lg border border-line bg-surface-raised p-4 shadow-card">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 class="text-[15px] font-semibold text-ink">{{ projectPanel.project.name }}</h2>
                    <p class="text-xs text-ink-soft">{{ projectPanel.date }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <p class="text-sm font-semibold">
                        <span class="text-status-ok">{{ projectPanel.present }}</span>
                        <span class="text-ink-soft"> / {{ projectPanel.assigned }} </span>
                        <Bilingual k="attendance.roster_present_of" inline />
                    </p>
                    <div v-if="can.export && projectPanel.rows.length" class="flex items-center gap-2">
                        <VButton variant="secondary" size="sm" icon="download" @click="exportPanel('excel')">Excel</VButton>
                        <VButton variant="secondary" size="sm" icon="download" @click="exportPanel('pdf')">PDF</VButton>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto rounded-md border border-line">
                <table class="w-full min-w-max text-sm">
                    <thead>
                        <tr class="border-b border-line bg-surface-sunken/60 text-xs text-ink-soft">
                            <th class="px-3 py-2 text-start"><Bilingual k="attendance.employee" inline /></th>
                            <th class="px-3 py-2 text-center"><Bilingual k="attendance.check_in" inline /></th>
                            <th class="px-3 py-2 text-center"><Bilingual k="attendance.check_out" inline /></th>
                            <th class="px-3 py-2 text-end"><Bilingual k="attendance.hours_worked" inline /></th>
                            <th class="px-3 py-2 text-start"><Bilingual k="attendance.status" inline /></th>
                            <th class="px-3 py-2 text-end"><Bilingual k="attendance.distance" inline /></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(row, i) in projectPanel.rows" :key="i" class="hover:bg-surface-hover/50">
                            <td class="px-3 py-2">
                                <span class="block font-medium text-ink">{{ row.employee }}</span>
                                <span class="block text-xs text-muted">{{ row.designation ?? '—' }}</span>
                            </td>
                            <td class="tabular-nums px-3 py-2 text-center">{{ row.check_in ?? '—' }}</td>
                            <td class="tabular-nums px-3 py-2 text-center">{{ row.check_out ?? '—' }}</td>
                            <td class="tabular-nums px-3 py-2 text-end">{{ row.hours != null ? `${row.hours}h` : '—' }}</td>
                            <td class="px-3 py-2">
                                <span class="text-xs font-semibold" :class="panelStatusStyle[row.status] ?? 'text-ink-soft'">
                                    <Bilingual :k="`attendance.roster_status_${row.status}`" inline />
                                </span>
                            </td>
                            <td class="tabular-nums px-3 py-2 text-end">
                                <span v-if="row.distance != null" :class="{
                                    'text-status-ok': row.distance_band === 'on_site',
                                    'text-status-warn': row.distance_band === 'near_site',
                                    'text-status-danger': row.distance_band === 'off_site',
                                }">{{ Math.round(row.distance) }}m</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                        </tr>
                        <tr v-if="projectPanel.rows.length === 0">
                            <td colspan="6" class="px-3 py-4 text-center text-sm text-muted">
                                <Bilingual k="attendance.roster_no_workers" inline />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
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
                        <td class="px-3 py-2 font-medium">{{ emp.deployed ? $t('attendance.deployed_worker') : emp.full_name }}</td>
                        <td class="tabular-nums px-3 py-2 text-end">{{ summary[emp.id]?.days_present ?? 0 }}</td>
                        <td class="tabular-nums px-3 py-2 text-end">{{ summary[emp.id]?.hours ?? 0 }}</td>
                        <td class="tabular-nums px-3 py-2 text-end">{{ summary[emp.id]?.overtime ?? 0 }}</td>
                        <td class="tabular-nums px-3 py-2 text-end">{{ summary[emp.id]?.absences ?? 0 }}</td>
                        <!-- A deployed worker's pay belongs to the home company — the host sees presence, not money. -->
                        <td v-if="canSeeWage" class="tabular-nums px-3 py-2 text-end">{{ emp.deployed ? '—' : (summary[emp.id]?.total_wage ?? 0) + ' €' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AttendanceModal :open="showModal" :record="modalRecord"
            :preset-employee="preset.employee" :preset-date="preset.date"
            :employees="employees" :projects="modalProjects"
            :project-assignments="projectAssignments"
            :can-see-wage="canSeeWage"
            @close="showModal = false" />

        <BulkAttendanceModal :open="showBulkModal"
            :employees="employees" :projects="formProjects"
            :project-assignments="projectAssignments"
            :can-see-wage="canSeeWage"
            :month="month"
            @close="showBulkModal = false" />

        <WeekendOfferModal :open="showOfferModal"
            :employees="employees" :projects="formProjects"
            @close="showOfferModal = false" />
    </AppLayout>
</template>
