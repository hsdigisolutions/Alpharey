<script setup>
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    modelValue: { type: [String, Number, null], default: null },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="relative">
        <select :id="id" :value="modelValue" :disabled="disabled"
            class="w-full appearance-none rounded-md border bg-surface-raised py-2 ps-3 pe-9 text-sm text-ink transition-colors duration-150 disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60"
            :class="invalid ? 'border-status-danger' : 'border-line hover:border-line-strong'"
            @change="$emit('update:modelValue', $event.target.value)">
            <slot />
        </select>
        <AppIcon name="chevron-down" class="pointer-events-none absolute end-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
    </div>
</template>
