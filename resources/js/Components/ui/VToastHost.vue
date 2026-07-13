<script setup>
/**
 * Flash toast host — mount once in the app layout. Shows session flash
 * messages (props.flash from HandleInertiaRequests) and auto-dismisses.
 */
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';

const page = usePage();
const toasts = ref([]);
let nextId = 1;

function push(status, message) {
    const id = nextId++;
    toasts.value.push({ id, status, message });
    setTimeout(() => dismiss(id), 4500);
}

function dismiss(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) push('ok', flash.success);
        if (flash?.error) push('danger', flash.error);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-2 px-4" aria-live="polite">
        <transition-group
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="-translate-y-2 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0">
            <div v-for="toast in toasts" :key="toast.id"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-lg border border-line bg-surface-raised p-3 text-sm shadow-raised">
                <AppIcon :name="toast.status === 'ok' ? 'check' : 'alert'" class="mt-0.5 h-4 w-4 shrink-0"
                    :class="toast.status === 'ok' ? 'text-status-ok' : 'text-status-danger'" />
                <p class="min-w-0 flex-1 text-ink">{{ toast.message }}</p>
                <button type="button" class="rounded-sm p-0.5 text-muted hover:text-ink" aria-label="Cerrar / Close"
                    @click="dismiss(toast.id)">
                    <AppIcon name="x" class="h-3.5 w-3.5" />
                </button>
            </div>
        </transition-group>
    </div>
</template>
