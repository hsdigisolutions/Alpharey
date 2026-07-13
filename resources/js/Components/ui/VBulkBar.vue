<script setup>
/**
 * Appears above a table when rows are selected — count + bulk actions.
 */
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    count: { type: Number, default: 0 },
});

defineEmits(['clear']);
</script>

<template>
    <transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="-translate-y-1 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0">
        <div v-if="count > 0"
            class="mb-3 flex items-center gap-3 rounded-lg border border-accent/40 bg-accent-soft px-3 py-2">
            <p class="tabular-nums text-sm font-medium text-accent">
                {{ count }} <Bilingual k="table.selected" inline class="text-sm" />
            </p>
            <div class="flex items-center gap-1.5">
                <slot />
            </div>
            <button type="button" class="ms-auto rounded-md p-1 text-accent hover:bg-accent/10"
                aria-label="Deseleccionar / Clear selection" @click="$emit('clear')">
                <AppIcon name="x" class="h-4 w-4" />
            </button>
        </div>
    </transition>
</template>
