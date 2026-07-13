<script setup>
defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <input :id="id" :type="type" :value="modelValue" :placeholder="placeholder" :disabled="disabled"
        class="w-full rounded-md border bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-faint transition-colors duration-150 disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60"
        :class="invalid ? 'border-status-danger' : 'border-line hover:border-line-strong'"
        @input="$emit('update:modelValue', $event.target.value)" />
</template>
