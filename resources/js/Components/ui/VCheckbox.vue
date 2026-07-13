<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    id: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <label class="inline-flex cursor-pointer items-center gap-2" :class="{ 'cursor-not-allowed opacity-60': disabled }">
        <input :id="id" type="checkbox" :checked="modelValue" :disabled="disabled"
            class="h-4 w-4 rounded-sm border-line text-accent accent-[var(--color-accent)]"
            @change="$emit('update:modelValue', $event.target.checked)" />
        <span class="text-sm text-ink"><slot /></span>
    </label>
</template>
