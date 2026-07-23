<script setup>
/**
 * The worker's month at a glance: one dot per day.
 *   green  = present (checked in)
 *   red    = absent (a recorded absence)
 *   blue   = approved leave
 *   grey   = weekend, future date, or a day with no record
 *
 * Deliberately read-only — a worker cannot change history from here, only see
 * it. The grid starts on Monday (Spanish/EU convention) and offsets the first
 * week so the columns line up under their weekday letters.
 */
import { computed } from 'vue';

const props = defineProps({
    month: { type: Object, required: true },
});

// Monday-first weekday initials, both languages folded into one letter.
const weekdayLetters = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

// Blank cells before day 1 so it sits under the right weekday.
const leadingBlanks = computed(() => (props.month.calendar[0]?.weekday ?? 0));

const dotClass = {
    present: 'bg-status-ok',
    absent: 'bg-status-danger',
    leave: 'bg-status-info',
    none: 'bg-transparent',
};
</script>

<template>
    <div>
        <div class="mb-2 grid grid-cols-7 gap-1 text-center">
            <span v-for="letter in weekdayLetters" :key="letter" class="text-[11px] font-medium text-muted">
                {{ letter }}
            </span>
        </div>

        <div class="grid grid-cols-7 gap-1">
            <span v-for="n in leadingBlanks" :key="`b${n}`" aria-hidden="true" />

            <div v-for="cell in month.calendar" :key="cell.day"
                class="flex aspect-square flex-col items-center justify-center rounded-md"
                :class="[
                    cell.status === 'none' ? 'bg-surface-sunken/60' : 'bg-surface-sunken',
                    // The one day the worker can act on gets a coral ring so it
                    // is unmistakable which cell 'today' is.
                    cell.is_today ? 'ring-2 ring-accent' : '',
                ]">
                <span class="text-[11px] leading-none"
                    :class="[cell.status === 'none' ? 'text-muted' : 'text-ink', cell.is_today ? 'font-bold text-accent' : '']">
                    {{ cell.day }}
                </span>
                <span v-if="cell.status !== 'none'"
                    class="mt-1 h-1.5 w-1.5 rounded-full"
                    :class="dotClass[cell.status]" />
            </div>
        </div>
    </div>
</template>
