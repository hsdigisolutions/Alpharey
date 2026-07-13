<script setup>
/**
 * One cell of the permission matrix (Screen 17): a toggle, or an em-dash
 * when the action doesn't apply to the module.
 */
import VToggle from '@/Components/ui/VToggle.vue';

defineProps({
    modelValue: { type: [Boolean, null], default: false }, // null = not applicable
    label: { type: String, required: true }, // accessible name: "Empleados — Ver / View"
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <span v-if="modelValue === null" class="inline-block w-9 text-center text-muted" aria-hidden="true">—</span>
    <VToggle v-else :model-value="modelValue" size="sm" :label="label" :disabled="disabled"
        @update:model-value="$emit('update:modelValue', $event)" />
</template>
