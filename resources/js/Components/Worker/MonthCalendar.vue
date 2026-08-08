<script setup>
/**
 * The worker's month at a glance. Each cell shows the day type:
 *   C  = jornada completa (green)   ·  M = media jornada (amber)
 *   hours = por horas (blue)        ·  m = por metros (coral)
 *   A  = ausente (red)              ·  grey = weekend / future / no record
 *
 * Read-only history. Tapping a day with a record reveals its detail (project,
 * hours, amount) below the grid. The grid starts on Monday (EU convention).
 */
import { computed, ref } from 'vue';
import { t } from '@/translate';

const props = defineProps({
    month: { type: Object, required: true },
});

const weekdayLetters = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
const leadingBlanks = computed(() => (props.month.calendar[0]?.weekday ?? 0));

const selected = ref(null);

function cellClass(cell) {
    if (cell.status === 'absent') return 'bg-status-danger-soft text-status-danger';
    if (cell.status === 'leave') return 'bg-status-info-soft text-status-info';
    if (cell.status !== 'present') return 'bg-surface-sunken/60 text-muted';
    switch (cell.day_type) {
        case 'half': return 'bg-status-warn-soft text-status-warn';
        case 'hourly': return 'bg-status-info-soft text-status-info';
        case 'per_meter': return 'bg-accent-soft text-accent';
        default: return 'bg-status-ok-soft text-status-ok';
    }
}

function marker(cell) {
    if (cell.status === 'absent') return 'A';
    if (cell.status === 'leave') return 'P';
    if (cell.status !== 'present') return '';
    switch (cell.day_type) {
        case 'full': return 'C';
        case 'half': return 'M';
        case 'per_meter': return `${Math.round(cell.quantity ?? 0)}m`;
        case 'hourly': return `${Math.round((cell.hours ?? 0) * 10) / 10}h`;
        default: return '';
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
            <span v-for="letter in weekdayLetters" :key="letter" class="text-[11px] font-medium text-muted">{{ letter }}</span>
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
