<script setup>
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    status: { type: String, default: 'info' }, // ok | warn | danger | info
});

const styles = {
    ok: 'bg-status-ok-soft text-status-ok',
    warn: 'bg-status-warn-soft text-status-warn',
    danger: 'bg-status-danger-soft text-status-danger',
    info: 'bg-status-info-soft text-status-info',
};

const icons = { ok: 'check', warn: 'alert', danger: 'alert', info: 'info' };
</script>

<template>
    <div class="flex items-start gap-2.5 rounded-md p-3 text-sm" :class="styles[status]" role="alert">
        <AppIcon :name="icons[status]" class="mt-0.5 h-4 w-4 shrink-0" />
        <div class="min-w-0"><slot /></div>
    </div>
</template>
