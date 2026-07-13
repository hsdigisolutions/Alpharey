<script setup>
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Buscar / Search' },
    id: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="relative">
        <AppIcon name="search" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
        <input :id="id" type="search" :value="modelValue" :placeholder="placeholder"
            class="w-full rounded-md border border-line bg-surface-raised py-2 ps-9 pe-8 text-sm text-ink placeholder:text-faint transition-colors duration-150 hover:border-line-strong"
            @input="$emit('update:modelValue', $event.target.value)" />
        <button v-if="modelValue" type="button"
            class="absolute end-2 top-1/2 -translate-y-1/2 rounded-sm p-0.5 text-muted hover:text-ink"
            aria-label="Borrar / Clear"
            @click="$emit('update:modelValue', '')">
            <AppIcon name="x" class="h-3.5 w-3.5" />
        </button>
    </div>
</template>
