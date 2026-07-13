<script setup>
defineProps({
    modelValue: { type: [String, null], default: null }, // YYYY-MM-DD
    type: { type: String, default: 'date' }, // date | time | month
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <input :id="id" :type="type" :value="modelValue" :disabled="disabled"
        class="tabular-nums w-full rounded-md border bg-surface-raised px-3 py-2 text-sm text-ink transition-colors duration-150 disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60"
        :class="invalid ? 'border-status-danger' : 'border-line hover:border-line-strong'"
        @input="$emit('update:modelValue', $event.target.value || null)" />
</template>
