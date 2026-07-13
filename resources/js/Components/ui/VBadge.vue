<script setup>
/**
 * Status badge — the traffic-light chip used across compliance, attendance,
 * invoices, and payroll.
 */
defineProps({
    status: { type: String, default: 'neutral' }, // ok | warn | danger | info | neutral | accent
});

const styles = {
    ok: 'bg-status-ok-soft text-status-ok',
    warn: 'bg-status-warn-soft text-status-warn',
    danger: 'bg-status-danger-soft text-status-danger',
    info: 'bg-status-info-soft text-status-info',
    neutral: 'bg-status-neutral-soft text-status-neutral',
    accent: 'bg-accent-soft text-accent',
};
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-sm px-1.5 py-0.5 text-[11px] font-medium" :class="styles[status]">
        <slot />
    </span>
</template>
