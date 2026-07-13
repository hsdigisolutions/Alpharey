<script setup>
/**
 * EUR amount input. Money is always tabular-nums and right-aligned.
 * Emits a number (or null when empty) — never a formatted string.
 */
defineProps({
    modelValue: { type: [Number, null], default: null },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

function onInput(event) {
    const raw = event.target.value;
    emit('update:modelValue', raw === '' ? null : Number(raw));
}
</script>

<template>
    <div class="relative">
        <input :id="id" type="number" step="0.01" inputmode="decimal" :value="modelValue" :disabled="disabled"
            class="tabular-nums w-full rounded-md border bg-surface-raised py-2 ps-3 pe-8 text-end text-sm text-ink placeholder:text-faint transition-colors duration-150 disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60"
            :class="invalid ? 'border-status-danger' : 'border-line hover:border-line-strong'"
            placeholder="0.00"
            @input="onInput" />
        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-sm text-muted">€</span>
    </div>
</template>
