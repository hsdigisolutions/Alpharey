<script setup>
/**
 * Attendance calendar cell (Screen 11): status color + hours + project.
 * Colors per spec: green present · amber late/early-leave · red absent ·
 * blue leave · grey weekend/empty.
 */
defineProps({
    status: { type: String, default: 'empty' }, // present | late | absent | leave | weekend | empty
    hours: { type: [Number, String], default: null },
    label: { type: String, default: null }, // project name
    compact: { type: Boolean, default: false }, // mobile simplified view
});

const styles = {
    present: 'bg-status-ok-soft text-status-ok',
    late: 'bg-status-warn-soft text-status-warn',
    absent: 'bg-status-danger-soft text-status-danger',
    leave: 'bg-status-info-soft text-status-info',
    weekend: 'bg-surface-sunken text-faint',
    empty: 'bg-surface-raised text-faint',
};

const dots = {
    present: 'bg-status-ok',
    late: 'bg-status-warn',
    absent: 'bg-status-danger',
    leave: 'bg-status-info',
    weekend: 'bg-status-neutral',
    empty: 'bg-line-strong',
};
</script>

<template>
    <button type="button"
        class="flex w-full flex-col items-start gap-0.5 rounded-sm border border-transparent p-1.5 text-start transition-colors duration-150 hover:border-line-strong"
        :class="[styles[status], compact ? 'min-h-8' : 'min-h-14']">
        <span class="flex w-full items-center gap-1">
            <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="dots[status]" />
            <span v-if="hours !== null" class="tabular-nums text-xs font-semibold">{{ hours }}h</span>
        </span>
        <span v-if="label && !compact" class="w-full truncate text-[10px] leading-tight opacity-80">{{ label }}</span>
    </button>
</template>
