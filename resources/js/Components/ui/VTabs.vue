<script setup>
/**
 * Detail-page tabs (REQUIREMENTS.md core UX rule: tabs inside detail pages,
 * never separate pages). Horizontally scrollable on mobile.
 */
defineProps({
    tabs: { type: Array, required: true }, // [{ key, labelKey, count? }]
    modelValue: { type: String, required: true },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <nav class="scrollbar-none -mb-px flex gap-1 overflow-x-auto border-b border-line" role="tablist">
        <button v-for="tab in tabs" :key="tab.key" type="button" role="tab"
            :aria-selected="tab.key === modelValue"
            class="flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors duration-150"
            :class="tab.key === modelValue
                ? 'border-accent text-ink'
                : 'border-transparent text-muted hover:border-line-strong hover:text-ink-soft'"
            @click="$emit('update:modelValue', tab.key)">
            <Bilingual :k="tab.labelKey" class="text-sm font-medium" />
            <span v-if="tab.count !== undefined"
                class="tabular-nums rounded-sm bg-surface-sunken px-1.5 py-0.5 text-[11px] font-medium text-ink-soft">
                {{ tab.count }}
            </span>
        </button>
    </nav>
</template>
