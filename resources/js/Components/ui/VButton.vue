<script setup>
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    variant: { type: String, default: 'primary' }, // primary | secondary | ghost | danger
    size: { type: String, default: 'md' }, // md | sm
    type: { type: String, default: 'button' },
    icon: { type: String, default: null },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const variants = {
    primary: 'bg-accent text-on-accent hover:bg-accent-hover shadow-card',
    secondary: 'border border-line bg-surface-raised text-ink hover:bg-surface-hover',
    ghost: 'text-ink-soft hover:bg-surface-sunken hover:text-ink',
    danger: 'bg-status-danger text-white hover:opacity-90 shadow-card',
};

const sizes = {
    lg: 'px-5 py-4 text-base gap-2',
    md: 'px-3.5 py-2 text-sm gap-2',
    sm: 'px-2.5 py-1.5 text-xs gap-1.5',
};
</script>

<template>
    <button :type="type" :disabled="disabled || loading"
        class="inline-flex items-center justify-center rounded-md font-medium transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-50"
        :class="[variants[variant], sizes[size]]">
        <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-90" d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <AppIcon v-else-if="icon" :name="icon" :class="size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4'" />
        <slot />
    </button>
</template>
