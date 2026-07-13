<script setup>
defineProps({
    status: { type: String, default: 'neutral' }, // ok | warn | danger | info | neutral
    pulse: { type: Boolean, default: false }, // e.g. overdue follow-ups
});

const colors = {
    ok: 'bg-status-ok',
    warn: 'bg-status-warn',
    danger: 'bg-status-danger',
    info: 'bg-status-info',
    neutral: 'bg-status-neutral',
};
</script>

<template>
    <span class="relative inline-flex h-2 w-2">
        <span v-if="pulse" class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60" :class="colors[status]" />
        <span class="relative inline-flex h-2 w-2 rounded-full" :class="colors[status]" />
    </span>
</template>
