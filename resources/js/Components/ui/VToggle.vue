<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    label: { type: String, default: null }, // accessible name (required when no visible label)
    size: { type: String, default: 'md' }, // md | sm
});

defineEmits(['update:modelValue']);
</script>

<template>
    <button type="button" role="switch" :aria-checked="modelValue" :aria-label="label" :disabled="disabled"
        class="relative inline-flex shrink-0 items-center rounded-full transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-50"
        :class="[
            size === 'sm' ? 'h-5 w-9' : 'h-6 w-11',
            modelValue ? 'bg-accent' : 'bg-line-strong',
        ]"
        @click="$emit('update:modelValue', !modelValue)">
        <span class="inline-block transform rounded-full bg-white shadow-card transition-transform duration-150"
            :class="[
                size === 'sm' ? 'h-3.5 w-3.5' : 'h-4.5 w-4.5',
                modelValue ? (size === 'sm' ? 'translate-x-[1.15rem]' : 'translate-x-[1.4rem]') : 'translate-x-1',
            ]" />
    </button>
</template>
