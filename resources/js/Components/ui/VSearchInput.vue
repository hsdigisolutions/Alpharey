<script setup>
import { computed } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';
import { tPair } from '@/translate';

const props = defineProps({
    modelValue: { type: String, default: '' },
    // null => the translated ui.common.search, in the active language order
    placeholder: { type: String, default: null },
    id: { type: String, default: null },
});

defineEmits(['update:modelValue']);

const resolvedPlaceholder = computed(() => props.placeholder ?? tPair('common.search'));
</script>

<template>
    <div class="relative">
        <AppIcon name="search" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
        <input :id="id" type="search" :value="modelValue" :placeholder="resolvedPlaceholder"
            class="w-full rounded-md border border-line bg-surface-raised py-2 ps-9 pe-8 text-sm text-ink placeholder:text-faint transition-colors duration-150 hover:border-line-strong"
            @input="$emit('update:modelValue', $event.target.value)" />
        <button v-if="modelValue" type="button"
            class="absolute end-2 top-1/2 -translate-y-1/2 rounded-sm p-0.5 text-muted hover:text-ink"
            :aria-label="$tPair('common.clear')"
            @click="$emit('update:modelValue', '')">
            <AppIcon name="x" class="h-3.5 w-3.5" />
        </button>
    </div>
</template>
