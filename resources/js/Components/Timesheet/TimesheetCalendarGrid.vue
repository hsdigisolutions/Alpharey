<script setup>
/**
 * Timesheet — By-Project CALENDAR / spreadsheet grid. A month (or period) matrix:
 * workers down the sticky left column, every day across the top, a day-type tile
 * in each present cell, a per-worker TOTAL (days + net hours) frozen on the right,
 * and a two-row footer — workers-present and total net hours per day — with the
 * grand totals in the corner.
 *
 * Hours are the SAME displayHoursNet values the table view and summary use (the
 * controller builds this from the identical per-day rows), so the two views
 * reconcile to the cent — no second calculation lives here.
 *
 * Design: reuses the Attendance-grid language (sticky column, weekend wash,
 * day-type status tokens) refined — both frozen columns cast a soft shadow,
 * present cells are tasteful soft-token tiles (F / H / hours), smooth row hover.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    sheet: { type: Object, required: true },
});

const page = usePage();
const locale = computed(() => (page.props.locale?.primary === 'es' ? 'es-ES' : 'en-GB'));

const days = computed(() => props.sheet.calendar?.days ?? []);
const dailyPresent = computed(() => props.sheet.calendar?.daily_present ?? {});
const dailyHours = computed(() => props.sheet.calendar?.daily_hours ?? {});
const rows = computed(() => props.sheet.rows ?? []);

// Per worker: date -> { day_type, hours } for O(1) cell lookup.
const byWorker = computed(() => {
    const map = {};
    for (const r of rows.value) {
        const d = {};
        for (const day of (r.days ?? [])) d[day.date] = day;
        map[r.employee_id] = d;
    }
    return map;
});

function weekdayShort(dateStr) {
    const w = new Date(`${dateStr}T00:00:00`)
        .toLocaleDateString(locale.value, { weekday: 'short' })
        .replace('.', '');
    return w.charAt(0).toUpperCase() + w.slice(1);
}

// Tasteful day-type tile: soft status token + a one-glyph mark. Full = F (green),
// half = H (amber), hourly/per-meter = the net hours (blue).
const TILE = {
    full: { cls: 'bg-status-ok-soft text-status-ok', label: 'F' },
    half: { cls: 'bg-status-warn-soft text-status-warn', label: 'H' },
    hourly: { cls: 'bg-status-info-soft text-status-info', label: null },
    per_meter: { cls: 'bg-status-info-soft text-status-info', label: null },
};
function tile(cell) {
    const t = TILE[cell?.day_type] ?? TILE.hourly;
    return { cls: t.cls, text: t.label ?? fmtHours(cell?.hours) };
}
function fmtHours(h) {
    if (h == null) return '';
    return Number.isInteger(h) ? String(h) : (Math.round(h * 10) / 10).toFixed(1);
}
</script>

<template>
    <div>
        <div class="overflow-x-auto rounded-xl border border-line bg-surface-raised shadow-card">
            <table class="min-w-max border-separate border-spacing-0 text-xs">
                <thead>
                    <tr>
                        <th scope="col"
                            class="sticky start-0 z-20 min-w-44 border-b border-e border-line bg-surface-sunken px-4 py-2.5 text-start text-[11px] font-semibold uppercase tracking-wide text-muted shadow-[6px_0_8px_-6px_rgba(0,0,0,0.10)]">
                            {{ $t('today.employee') }}
                        </th>
                        <th v-for="d in days" :key="d.date" scope="col"
                            class="w-11 border-b border-line px-0 py-1.5 text-center align-middle"
                            :class="d.weekend ? 'bg-surface-sunken/60' : ''">
                            <div class="text-[10px] font-medium uppercase leading-tight"
                                :class="d.weekend ? 'text-muted' : 'text-ink-soft'">{{ weekdayShort(d.date) }}</div>
                            <div class="tabular-nums text-sm font-semibold leading-tight"
                                :class="d.weekend ? 'text-muted' : 'text-ink'">{{ d.day }}</div>
                        </th>
                        <th scope="col"
                            class="sticky end-0 z-20 border-b border-s border-line bg-surface-sunken px-4 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wide text-muted shadow-[-6px_0_8px_-6px_rgba(0,0,0,0.10)]">
                            {{ $t('timesheet.total') }}
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="row in rows" :key="row.employee_id" class="group">
                        <th scope="row"
                            class="sticky start-0 z-10 min-w-44 border-b border-e border-line bg-surface-raised px-4 py-2 text-start font-normal shadow-[6px_0_8px_-6px_rgba(0,0,0,0.08)] transition-colors group-hover:bg-surface-hover">
                            <div class="truncate font-semibold text-ink">{{ row.employee }}</div>
                            <div v-if="row.designation" class="truncate text-[11px] text-muted">{{ row.designation }}</div>
                        </th>

                        <td v-for="d in days" :key="d.date"
                            class="border-b border-line/70 px-0 py-1.5 text-center align-middle transition-colors group-hover:bg-surface-hover"
                            :class="d.weekend && !byWorker[row.employee_id]?.[d.date] ? 'bg-surface-sunken/30' : ''">
                            <span v-if="byWorker[row.employee_id]?.[d.date]"
                                class="inline-flex h-6 min-w-[1.5rem] items-center justify-center rounded-md px-1 text-[11px] font-semibold tabular-nums transition group-hover:ring-1 group-hover:ring-line-strong"
                                :class="tile(byWorker[row.employee_id][d.date]).cls"
                                :title="`${row.employee} · ${d.date} · ${byWorker[row.employee_id][d.date].hours}h`">
                                {{ tile(byWorker[row.employee_id][d.date]).text }}
                            </span>
                        </td>

                        <td class="sticky end-0 z-10 border-b border-s border-line bg-surface-raised px-4 py-2 text-center shadow-[-6px_0_8px_-6px_rgba(0,0,0,0.08)] transition-colors group-hover:bg-surface-hover">
                            <div class="tabular-nums text-sm font-semibold text-ink">{{ row.days_present }} <span class="text-[10px] font-normal text-muted">{{ $t('timesheet.d_short') }}</span></div>
                            <div class="tabular-nums text-[11px] font-medium text-accent">{{ fmtHours(row.hours) }}h</div>
                        </td>
                    </tr>

                    <tr v-if="!rows.length">
                        <td :colspan="days.length + 2" class="px-4 py-8 text-center text-sm text-muted">
                            {{ $t('timesheet.no_rows') }}
                        </td>
                    </tr>
                </tbody>

                <tfoot v-if="rows.length">
                    <!-- Row 1 — workers present per day -->
                    <tr>
                        <th scope="row"
                            class="sticky start-0 z-10 border-t border-e border-line bg-surface-sunken px-4 py-2 text-start text-[11px] font-semibold uppercase tracking-wide text-ink-soft shadow-[6px_0_8px_-6px_rgba(0,0,0,0.08)]">
                            {{ $t('timesheet.daily_workers') }}
                        </th>
                        <td v-for="d in days" :key="d.date"
                            class="border-t border-line bg-surface-sunken/70 px-0 py-2 text-center"
                            :class="d.weekend ? 'bg-surface-sunken' : ''">
                            <span class="tabular-nums text-[11px] font-semibold"
                                :class="(dailyPresent[d.date] ?? 0) > 0 ? 'text-ink' : 'text-faint'">{{ dailyPresent[d.date] ?? 0 }}</span>
                        </td>
                        <td class="sticky end-0 z-10 border-t border-s border-line bg-accent-soft px-4 py-2 text-center shadow-[-6px_0_8px_-6px_rgba(0,0,0,0.08)]">
                            <span class="tabular-nums text-sm font-bold text-accent">{{ sheet.calendar?.grand_total_days ?? 0 }}</span>
                        </td>
                    </tr>
                    <!-- Row 2 — total net hours per day -->
                    <tr>
                        <th scope="row"
                            class="sticky start-0 z-10 border-t border-e border-line bg-surface-sunken px-4 py-2 text-start text-[11px] font-semibold uppercase tracking-wide text-ink-soft shadow-[6px_0_8px_-6px_rgba(0,0,0,0.08)]">
                            {{ $t('timesheet.daily_hours') }}
                        </th>
                        <td v-for="d in days" :key="d.date"
                            class="border-t border-line bg-surface-sunken/70 px-0 py-2 text-center"
                            :class="d.weekend ? 'bg-surface-sunken' : ''">
                            <span class="tabular-nums text-[11px] font-medium"
                                :class="(dailyHours[d.date] ?? 0) > 0 ? 'text-accent' : 'text-faint'">{{ fmtHours(dailyHours[d.date] ?? 0) }}</span>
                        </td>
                        <td class="sticky end-0 z-10 border-t border-s border-line bg-accent-soft px-4 py-2 text-center shadow-[-6px_0_8px_-6px_rgba(0,0,0,0.08)]">
                            <span class="tabular-nums text-sm font-bold text-accent">{{ fmtHours(sheet.calendar?.grand_total_hours ?? 0) }}h</span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Legend -->
        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 px-1 text-[11px] text-ink-soft">
            <span class="inline-flex items-center gap-1.5">
                <span class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-status-ok-soft text-[10px] font-semibold text-status-ok">F</span>
                {{ $t('timesheet.dt_full') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-status-warn-soft text-[10px] font-semibold text-status-warn">H</span>
                {{ $t('timesheet.dt_half') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-md bg-status-info-soft px-1 text-[10px] font-semibold text-status-info">6</span>
                {{ $t('timesheet.dt_hourly') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="inline-block h-4 w-4 rounded bg-surface-sunken/60"></span>
                {{ $t('timesheet.legend_weekend') }}
            </span>
        </div>
    </div>
</template>
