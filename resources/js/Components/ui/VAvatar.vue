<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    size: { type: String, default: 'md' }, // sm | md | lg
});

const initials = computed(() =>
    props.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join(''),
);

const sizes = {
    sm: 'h-6 w-6 text-[10px]',
    md: 'h-8 w-8 text-xs',
    lg: 'h-11 w-11 text-sm',
};
</script>

<template>
    <span class="inline-flex shrink-0 items-center justify-center rounded-full bg-accent-soft font-semibold text-accent"
        :class="sizes[size]" :title="name">
        {{ initials }}
    </span>
</template>
