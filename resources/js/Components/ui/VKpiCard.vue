<script setup>
/**
 * KPI / summary card. Used both as a static dashboard metric (Screen 03) and,
 * when `clickable`, as a summary card that filters a list page on click.
 * Values are always tabular-nums; `sub` renders a secondary line (e.g. € total).
 */
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    k: { type: String, required: true },
    value: { type: [String, Number], default: '—' },
    icon: { type: String, default: null },
    status: { type: String, default: null }, // tints the value: ok | warn | danger
    sub: { type: [String, Number], default: null }, // secondary line (e.g. € total)
    clickable: { type: Boolean, default: false },
    active: { type: Boolean, default: false }, // ringed when its filter is applied
});

defineEmits(['click']);
</script>

<template>
    <component
        :is="clickable ? 'button' : 'div'"
        :type="clickable ? 'button' : undefined"
        class="block w-full rounded-lg border bg-surface-raised p-4 text-start shadow-card transition"
        :class="[
            clickable ? 'cursor-pointer hover:bg-surface-hover' : '',
            active ? 'border-accent ring-1 ring-accent' : 'border-line',
        ]"
        @click="clickable && $emit('click')">
        <div class="flex items-start justify-between gap-2">
            <Bilingual :k="k" class="text-xs font-medium text-ink-soft" />
            <span v-if="icon" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent">
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
        <p v-if="sub !== null" class="tabular-nums mt-0.5 text-xs text-ink-soft">{{ sub }}</p>
    </component>
</template>
