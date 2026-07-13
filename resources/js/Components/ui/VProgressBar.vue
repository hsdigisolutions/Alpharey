<script setup>
defineProps({
    percent: { type: Number, required: true }, // 0–100
    status: { type: String, default: 'accent' }, // accent | ok | warn | danger
    size: { type: String, default: 'md' }, // md | sm
});

const colors = {
    accent: 'bg-accent',
    ok: 'bg-status-ok',
    warn: 'bg-status-warn',
    danger: 'bg-status-danger',
};
</script>

<template>
    <div class="w-full overflow-hidden rounded-full bg-surface-sunken" :class="size === 'sm' ? 'h-1.5' : 'h-2'"
        role="progressbar" :aria-valuenow="Math.round(percent)" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full rounded-full transition-[width] duration-300" :class="colors[status]"
            :style="{ width: `${Math.min(Math.max(percent, 0), 100)}%` }" />
    </div>
</template>
