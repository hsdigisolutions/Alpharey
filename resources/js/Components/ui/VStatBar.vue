<script setup>
/**
 * Labeled score bar — the Compliance Center per-company pattern
 * (REQUIREMENTS.md Screen 16). Status color derives from the score:
 * ≥90 ok · ≥70 warn · below danger.
 */
import { computed } from 'vue';
import VProgressBar from '@/Components/ui/VProgressBar.vue';

const props = defineProps({
    label: { type: String, required: true },
    sublabel: { type: String, default: null },
    percent: { type: Number, required: true },
});

const status = computed(() => {
    if (props.percent >= 90) return 'ok';
    if (props.percent >= 70) return 'warn';
    return 'danger';
});
</script>

<template>
    <div class="flex items-center gap-3">
        <div class="w-44 min-w-0">
            <p class="truncate text-sm font-medium">{{ label }}</p>
            <p v-if="sublabel" class="truncate text-xs text-muted">{{ sublabel }}</p>
        </div>
        <div class="flex-1">
            <VProgressBar :percent="percent" :status="status" />
        </div>
        <p class="tabular-nums w-12 text-end text-sm font-semibold"
            :class="{
                'text-status-ok': status === 'ok',
                'text-status-warn': status === 'warn',
                'text-status-danger': status === 'danger',
            }">
            {{ Math.round(percent) }}%
        </p>
    </div>
</template>
