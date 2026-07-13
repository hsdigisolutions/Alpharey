<script setup>
/**
 * Dashboard KPI card (Screen 03). Values are always tabular-nums.
 */
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    k: { type: String, required: true },
    value: { type: [String, Number], default: '—' },
    icon: { type: String, default: null },
    status: { type: String, default: null }, // tints the value: ok | warn | danger
});
</script>

<template>
    <div class="rounded-lg border border-line bg-surface-raised p-4 shadow-card">
        <div class="flex items-start justify-between gap-2">
            <Bilingual :k="k" class="text-xs font-medium text-ink-soft" />
            <span v-if="icon" class="rounded-md bg-surface-sunken p-1.5 text-muted">
                <AppIcon :name="icon" class="h-4 w-4" />
            </span>
        </div>
        <p class="tabular-nums mt-2 text-2xl font-semibold"
            :class="{
                'text-status-ok': status === 'ok',
                'text-status-warn': status === 'warn',
                'text-status-danger': status === 'danger',
            }">
            {{ value }}
        </p>
    </div>
</template>
