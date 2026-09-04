<script setup>
/**
 * Timesheet — a weekly (or monthly) per-employee view of attendance. Admins
 * pick any employee, navigate by week/month, filter by project, and export.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VCard from '@/Components/ui/VCard.vue';
import VButton from '@/Components/ui/VButton.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import AppIcon from '@/Components/AppIcon.vue';
import TimesheetCalendarGrid from '@/Components/Timesheet/TimesheetCalendarGrid.vue';

const props = defineProps({
    employees: { type: Array, required: true },
    projects: { type: Array, required: true },
    filters: { type: Object, required: true },
    period: { type: Object, required: true },
    sheet: { type: Object, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const locale = computed(() => (page.props.locale?.primary === 'es' ? 'es-ES' : 'en-GB'));

const state = reactive({
    view: props.filters.view ?? 'employee',
    employee: props.filters.employee ?? '',
    mode: props.filters.mode ?? 'week',
    project: props.filters.project ?? '',
    from: props.filters.from ?? props.period.start,
    to: props.filters.to ?? props.period.end,
});

// By-Project sub-view: the current summary Table, or the new Calendar grid.
// Purely client-side — both render from the same `sheet` payload.
const projectView = ref('table');

// Which By-Project worker rows are expanded to show their worked dates.
const expanded = reactive({});
function toggleDates(id) {
    expanded[id] = !expanded[id];
}

// On-screen labels follow the ES/EN toggle (the EXPORT stays Spanish by design).
function weekdayLabel(dateStr) {
    const w = new Date(`${dateStr}T00:00:00`)
        .toLocaleDateString(locale.value, { weekday: 'short' })
        .replace('.', '');
    return w.charAt(0).toUpperCase() + w.slice(1);
}
function dayTypeStatus(dt) {
    return { full: 'ok', half: 'warn', hourly: 'info', per_meter: 'neutral' }[dt] ?? 'neutral';
}

function go(date) {
    router.get('/timesheet', {
        view: state.view,
        employee: state.employee || undefined,
        mode: state.mode,
        date: date ?? props.period.start,
        from: state.mode === 'custom' ? state.from : undefined,
        to: state.mode === 'custom' ? state.to : undefined,
        project: state.project || undefined,
    }, { preserveScroll: true, preserveState: true });
}

function shift(dir) {
    if (state.mode === 'custom') return; // custom uses explicit from/to
    const d = new Date(props.period.start);
    if (state.mode === 'month') d.setMonth(d.getMonth() + dir);
    else d.setDate(d.getDate() + dir * 7);
    go(d.toISOString().slice(0, 10));
}

function setView(v) { state.view = v; go(); }

const weekdayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const dayTypeBadge = { full: 'ok', half: 'warn', hourly: 'info', per_meter: 'info' };
const statusBadge = { present: 'ok', late: 'warn', early_leave: 'warn', absent: 'danger', leave: 'info' };

const periodLabel = computed(() => {
    const fmt = (s) => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' }).format(new Date(s));
    return `${fmt(props.period.start)} — ${fmt(props.period.end)}`;
});

function exportSheet(format) {
    const params = new URLSearchParams();
    params.append('view', state.view);
    if (state.employee) params.append('employee', state.employee);
    params.append('mode', state.mode);
    params.append('date', props.period.start);
    if (state.mode === 'custom') { params.append('from', state.from); params.append('to', state.to); }
    if (state.project) params.append('project', state.project);
    // By-Project: export whichever layout is on screen — the Calendar grid or the
    // summary Table. (The By-Employee view has only one layout, so it's ignored.)
    if (state.view === 'project' && state.project) params.append('display', projectView.value);
    params.append('format', format);
    window.location.href = `/timesheet/export?${params.toString()}`;
}
</script>

<template>
    <Head :title="$t('timesheet.title')" />

    <AppLayout>
        <VPageHeader k="timesheet.title" />

        <!-- Controls -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <!-- View: one employee's days, or all employees on a project -->
            <div class="inline-flex rounded-md border border-line-strong bg-surface-raised p-0.5">
                <button type="button" class="rounded px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="state.view === 'employee' ? 'bg-accent text-on-accent' : 'text-ink-soft hover:text-ink'"
                    @click="setView('employee')">{{ $t('timesheet.by_employee') }}</button>
                <button type="button" class="rounded px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="state.view === 'project' ? 'bg-accent text-on-accent' : 'text-ink-soft hover:text-ink'"
                    @click="setView('project')">{{ $t('timesheet.by_project') }}</button>
            </div>

            <VSelect v-if="state.view === 'employee'" v-model="state.employee" class="w-full sm:w-64" @update:model-value="go()">
                <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
            </VSelect>
            <VSelect v-model="state.project" class="w-full sm:w-52" @update:model-value="go()">
                <option value="">{{ state.view === 'project' ? $t('timesheet.select_project') : $t('timesheet.all_projects') }}</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </VSelect>
            <VSelect v-model="state.mode" class="w-32" @update:model-value="go()">
                <option value="week">{{ $t('timesheet.week') }}</option>
                <option value="month">{{ $t('timesheet.month') }}</option>
                <option value="custom">{{ $t('timesheet.custom') }}</option>
            </VSelect>

            <div class="ms-auto flex items-center gap-2">
                <template v-if="state.mode === 'custom'">
                    <input v-model="state.from" type="date"
                        class="rounded-md border border-line-strong bg-surface-sunken px-2 py-1 text-sm text-ink" @change="go()" />
                    <span class="text-muted">→</span>
                    <input v-model="state.to" type="date"
                        class="rounded-md border border-line-strong bg-surface-sunken px-2 py-1 text-sm text-ink" @change="go()" />
                </template>
                <template v-else>
                    <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="shift(-1)">
                        <AppIcon name="chevron-left" class="h-4 w-4" />
                    </button>
                    <span class="min-w-40 text-center text-sm font-semibold">{{ periodLabel }}</span>
                    <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="shift(1)">
                        <AppIcon name="chevron-right" class="h-4 w-4" />
                    </button>
                </template>
            </div>
        </div>

        <VCard>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-ink">
                    <template v-if="state.view === 'project'">
                        {{ $t('timesheet.workers') }}: <span class="tabular-nums">{{ sheet.workers ?? 0 }}</span>
                        · {{ $t('timesheet.week_total') }}: <span class="tabular-nums">{{ sheet.total_hours }}h</span>
                    </template>
                    <template v-else>
                        {{ $t('timesheet.week_total') }}: <span class="tabular-nums">{{ sheet.total_hours }}h</span>
                        · {{ $t('timesheet.days_present') }}: <span class="tabular-nums">{{ sheet.days_present }}</span>
                    </template>
                </p>
                <div class="flex items-center gap-2">
                    <!-- By-Project sub-view: Table (current) or Calendar grid. -->
                    <div v-if="state.view === 'project' && state.project"
                        class="inline-flex rounded-lg border border-line bg-surface-sunken p-0.5">
                        <button type="button"
                            class="rounded-md px-3 py-1 text-xs font-medium transition"
                            :class="projectView === 'table' ? 'bg-surface-raised text-ink shadow-card' : 'text-ink-soft hover:text-ink'"
                            @click="projectView = 'table'">{{ $t('timesheet.view_table') }}</button>
                        <button type="button"
                            class="rounded-md px-3 py-1 text-xs font-medium transition"
                            :class="projectView === 'calendar' ? 'bg-surface-raised text-ink shadow-card' : 'text-ink-soft hover:text-ink'"
                            @click="projectView = 'calendar'">{{ $t('timesheet.view_calendar') }}</button>
                    </div>
                    <div v-if="can.export" class="flex items-center gap-2">
                        <VButton variant="secondary" size="sm" icon="download" @click="exportSheet('excel')">Excel</VButton>
                        <VButton variant="secondary" size="sm" icon="download" @click="exportSheet('pdf')">PDF</VButton>
                    </div>
                </div>
            </div>

            <!-- By employee — the daily rows -->
            <div v-if="state.view === 'employee'" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs uppercase text-muted">
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.day') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.project') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.check_in') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.check_out') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('timesheet.hours') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.day_type') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in sheet.rows" :key="row.date" class="border-b border-line"
                            :class="row.weekday >= 6 ? 'bg-surface-sunken/40' : ''">
                            <td class="px-2 py-2">
                                <span class="font-medium text-ink">{{ $t(`weekdays.${weekdayKeys[row.weekday - 1]}`) }}</span>
                                <span class="ms-1 text-xs text-muted">{{ row.date.slice(8, 10) }}/{{ row.date.slice(5, 7) }}</span>
                            </td>
                            <td class="px-2 py-2 text-ink-soft">{{ row.project ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.check_in ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.check_out ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-end text-ink">{{ row.hours != null ? `${row.hours}h` : '—' }}</td>
                            <td class="px-2 py-2">
                                <VBadge v-if="row.day_type" :status="dayTypeBadge[row.day_type] ?? 'neutral'">
                                    <Bilingual :k="`attendance.day_type_${row.day_type}`" inline />
                                </VBadge>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="px-2 py-2">
                                <VBadge :status="statusBadge[row.status] ?? 'neutral'">
                                    <Bilingual :k="`attendance.roster_status_${row.status}`" inline />
                                </VBadge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- By project — all employees on the project -->
            <div v-else>
                <p v-if="!state.project" class="py-6 text-center text-sm text-muted">{{ $t('timesheet.pick_project') }}</p>
                <!-- Calendar / spreadsheet grid -->
                <TimesheetCalendarGrid v-else-if="projectView === 'calendar'" :sheet="sheet" />
                <!-- Table view (default; unchanged) -->
                <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs uppercase text-muted">
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.employee') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('employees.designation') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('timesheet.days_present') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('timesheet.hours') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in sheet.rows" :key="row.employee_id">
                            <tr class="border-b border-line" :class="expanded[row.employee_id] ? 'bg-surface-sunken' : ''">
                                <td class="px-2 py-2 text-ink">
                                    <button type="button" class="inline-flex items-center gap-1.5 text-start hover:text-accent"
                                        @click="toggleDates(row.employee_id)">
                                        <AppIcon :name="expanded[row.employee_id] ? 'chevron-down' : 'chevron-right'" class="h-3.5 w-3.5 shrink-0 text-muted" />
                                        <span>{{ row.employee }}</span>
                                    </button>
                                </td>
                                <td class="px-2 py-2 text-ink-soft">{{ row.designation ?? '—' }}</td>
                                <td class="tabular-nums px-2 py-2 text-end">{{ row.days_present }}</td>
                                <td class="tabular-nums px-2 py-2 text-end text-ink">{{ row.hours }}h</td>
                            </tr>
                            <!-- Expanded: the specific worked dates for this worker -->
                            <tr v-if="expanded[row.employee_id]">
                                <td colspan="4" class="bg-surface-sunken px-3 pb-4 pt-2 sm:px-6">
                                    <div class="overflow-hidden rounded-lg border border-line bg-surface-raised shadow-card">
                                        <table class="w-full text-xs">
                                            <thead>
                                                <tr class="border-b border-line bg-surface-sunken/60 text-[10px] uppercase tracking-wide text-muted">
                                                    <th class="px-3 py-2 text-start font-medium">{{ $t('timesheet.col_date') }}</th>
                                                    <th class="px-3 py-2 text-start font-medium">{{ $t('timesheet.col_weekday') }}</th>
                                                    <th class="px-3 py-2 text-start font-medium">{{ $t('timesheet.col_day_type') }}</th>
                                                    <th class="px-3 py-2 text-end font-medium">{{ $t('timesheet.hours') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="d in row.days" :key="d.date"
                                                    class="border-b border-line/70 last:border-0 hover:bg-surface-hover">
                                                    <td class="tabular-nums px-3 py-1.5 font-medium text-ink">{{ d.date_fmt }}</td>
                                                    <td class="px-3 py-1.5 text-ink-soft">{{ weekdayLabel(d.date) }}</td>
                                                    <td class="px-3 py-1.5">
                                                        <VBadge v-if="d.day_type" :status="dayTypeStatus(d.day_type)">
                                                            {{ $t('timesheet.dt_' + d.day_type) }}
                                                        </VBadge>
                                                        <span v-else class="text-muted">—</span>
                                                    </td>
                                                    <td class="tabular-nums px-3 py-1.5 text-end font-semibold text-ink">{{ d.hours }}h</td>
                                                </tr>
                                                <tr v-if="!row.days || row.days.length === 0">
                                                    <td colspan="4" class="px-3 py-2 text-center text-muted">{{ $t('timesheet.no_rows') }}</td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr class="border-t border-line bg-surface-sunken/40 text-[11px]">
                                                    <td class="px-3 py-2 font-medium text-ink-soft" colspan="3">
                                                        {{ row.days_present }} {{ $t('timesheet.days_present') }}
                                                    </td>
                                                    <td class="tabular-nums px-3 py-2 text-end font-semibold text-ink">{{ row.hours }}h</td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="sheet.rows.length === 0">
                            <td colspan="4" class="py-6 text-center text-sm text-muted">{{ $t('timesheet.no_rows') }}</td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </VCard>
    </AppLayout>
</template>
