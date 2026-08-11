<script setup>
/**
 * The worker's month at a glance. Each cell carries a short, LOCALE-AWARE label
 * (never a bare letter) — resolved through translation keys so ES and EN read
 * their own abbreviations:
 *   full day  → PF / FD (green)      ·  half day → PH / HD (amber)
 *   partial   → actual hours (blue)  ·  per_meter → metres (coral)
 *   absent    → AU / AB (light red, lighter when auto-generated)
 *   leave     → PE / LV (blue)       ·  weekend worked → FS / WE (purple)
 *   weekend, no work → empty grey cell
 *
 * Read-only history. Tapping a day with a record reveals its detail (project,
 * hours) below the grid. The grid starts on Monday (EU convention). A legend
 * under the grid explains the codes, also locale-aware.
 */
import { computed, ref } from 'vue';
import { t } from '@/translate';

const props = defineProps({
    month: { type: Object, required: true },
});

// Monday-first weekday headers, localised (Lun–Dom / Mon–Sun) — never the old
// hardcoded Spanish letters, which were unreadable in English (X for Wed …).
const weekdayLabels = computed(() => [
    t('weekdays.mon'), t('weekdays.tue'), t('weekdays.wed'), t('weekdays.thu'),
    t('weekdays.fri'), t('weekdays.sat'), t('weekdays.sun'),
]);
const leadingBlanks = computed(() => (props.month.calendar[0]?.weekday ?? 0));

const selected = ref(null);

// Sat = 5, Sun = 6 in the service's Monday-first weekday index.
function isWeekend(cell) {
    return cell.weekday >= 5;
}

function cellClass(cell) {
    if (cell.status === 'absent') {
        // A nightly auto-absence reads lighter than a manual one.
        return cell.is_auto_generated
            ? 'bg-status-danger-soft/50 text-status-danger'
            : 'bg-status-danger-soft text-status-danger';
    }
    if (cell.status === 'leave') return 'bg-status-info-soft text-status-info';
    if (cell.status !== 'present') return 'bg-surface-sunken/60 text-muted';
    // Weekend work is its own colour, regardless of the graded day type.
    if (isWeekend(cell)) return 'bg-accent-soft text-accent';
    switch (cell.day_type) {
        case 'half': return 'bg-status-warn-soft text-status-warn';
        case 'hourly': return 'bg-status-info-soft text-status-info';
        case 'per_meter': return 'bg-accent-soft text-accent';
        default: return 'bg-status-ok-soft text-status-ok';
    }
}

function marker(cell) {
    if (cell.status === 'absent') return t('worker.cal_absent');
    if (cell.status === 'leave') return t('worker.cal_leave');
    if (cell.status !== 'present') return '';
    if (isWeekend(cell)) return t('worker.cal_weekend');
    switch (cell.day_type) {
        case 'full': return t('worker.cal_full');
        case 'half': return t('worker.cal_half');
        case 'per_meter': return `${Math.round(cell.quantity ?? 0)}m`;
        case 'hourly': return `${Math.round((cell.hours ?? 0) * 10) / 10}h`;
        default: return t('worker.cal_full');
    }
}

function hoursHM(h) {
    const hh = Math.floor(Math.max(0, Number(h) || 0));
    const mm = Math.round((Math.max(0, Number(h) || 0) - hh) * 60);
    return `${hh}h ${String(mm).padStart(2, '0')}m`;
}

function select(cell) {
    selected.value = cell.status === 'none' ? null : cell;
}
</script>

<template>
    <div>
        <div class="mb-2 grid grid-cols-7 gap-1 text-center">
            <span v-for="label in weekdayLabels" :key="label" class="text-[11px] font-medium text-muted">{{ label }}</span>
        </div>

        <div class="grid grid-cols-7 gap-1">
            <span v-for="n in leadingBlanks" :key="`b${n}`" aria-hidden="true" />

            <button v-for="cell in month.calendar" :key="cell.day" type="button"
                class="relative flex aspect-square flex-col items-center justify-center rounded-md text-[11px] transition-colors"
                :class="[
                    cellClass(cell),
                    cell.is_today ? 'ring-2 ring-accent' : '',
                    selected && selected.day === cell.day ? 'outline outline-2 outline-accent' : '',
                ]"
                @click="select(cell)">
                <span class="absolute start-1 top-0.5 text-[9px] opacity-70">{{ cell.day }}</span>
                <span class="text-xs font-semibold leading-none">{{ marker(cell) }}</span>
            </button>
        </div>

        <!-- Tapped-day detail -->
        <div v-if="selected" class="mt-3 rounded-lg border border-line bg-surface-sunken p-3 text-sm">
            <div class="mb-1 flex items-center justify-between">
                <span class="font-semibold">{{ selected.day }}</span>
                <button type="button" class="text-xs text-muted hover:text-ink" @click="selected = null">✕</button>
            </div>
            <template v-if="selected.status === 'present'">
                <div class="flex justify-between py-0.5"><span class="text-ink-soft">{{ t('worker.total_hours') }}</span><span class="tabular-nums">{{ hoursHM(selected.hours) }}</span></div>
                <div v-if="selected.project" class="flex justify-between py-0.5"><span class="text-ink-soft">{{ t('worker.detail_project') }}</span><span class="truncate ps-2 text-end">{{ selected.project }}</span></div>
            </template>
            <p v-else class="text-ink-soft">
                {{ selected.status === 'absent' ? t('worker.legend_absent') : (selected.status === 'leave' ? t('worker.legend_present') : t('worker.detail_none')) }}
            </p>
        </div>
    </div>
</template>
