<script setup>
/**
 * Modal dialog — the create/edit convention (never navigate away). Opens
 * full-screen on mobile per REQUIREMENTS.md §14.
 */
import { onBeforeUnmount, onMounted, watch } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    titleKey: { type: String, required: true },
    size: { type: String, default: 'md' }, // sm | md | lg
});

const emit = defineEmits(['close']);

const sizes = {
    sm: 'md:max-w-md',
    md: 'md:max-w-xl',
    lg: 'md:max-w-3xl',
};

function onKeydown(event) {
    if (event.key === 'Escape' && props.open) {
        emit('close');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

// iOS Safari: position:fixed body pattern prevents the page from scrolling
// behind the modal while allowing the modal's own overflow-y-auto to work.
let savedScrollY = 0;
watch(
    () => props.open,
    (open) => {
        if (open) {
            savedScrollY = window.scrollY;
            document.body.style.position = 'fixed';
            document.body.style.top = `-${savedScrollY}px`;
            document.body.style.width = '100%';
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.width = '';
            document.body.style.overflow = '';
            window.scrollTo(0, savedScrollY);
        }
    },
);
</script>

<template>
    <Teleport to="body">
        <transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-40 flex items-end justify-center bg-black/40 md:items-center md:p-6"
                @click.self="emit('close')">
                <div role="dialog" aria-modal="true"
                    class="flex max-h-full w-full flex-col overflow-hidden bg-surface-raised shadow-overlay max-md:h-full md:rounded-xl md:border md:border-line"
                    :class="sizes[size]">
                    <header class="flex items-center justify-between border-b border-line px-5 py-4">
                        <h2 class="text-lg font-semibold">
                            <Bilingual :k="titleKey" />
                        </h2>
                        <button type="button" class="rounded-md p-1.5 text-muted hover:bg-surface-sunken hover:text-ink"
                            aria-label="Cerrar / Close" @click="emit('close')">
                            <AppIcon name="x" class="h-4.5 w-4.5" />
                        </button>
                    </header>
                    <div class="flex-1 overflow-y-auto px-5 py-4">
                        <slot />
                    </div>
                    <footer v-if="$slots.footer" class="flex items-center justify-end gap-2 border-t border-line px-5 py-3.5">
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </transition>
    </Teleport>
</template>
