<script setup>
import VBadge from '@/Components/ui/VBadge.vue';

defineProps({
    time: { type: String, required: true },
    author: { type: String, default: null },
    typeLabel: { type: String, default: null },
    typeStatus: { type: String, default: 'neutral' },
});
</script>

<template>
    <li class="relative">
        <span class="absolute -start-[1.62rem] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-surface-raised bg-accent" />
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <time class="tabular-nums text-xs text-muted">{{ time }}</time>
            <span v-if="author" class="text-xs font-medium text-ink-soft">{{ author }}</span>
            <VBadge v-if="typeLabel" :status="typeStatus">{{ typeLabel }}</VBadge>
        </div>
        <div class="mt-1 text-sm text-ink">
            <slot />
        </div>
        <div v-if="$slots.attachment" class="mt-2">
            <slot name="attachment" />
        </div>
    </li>
</template>
