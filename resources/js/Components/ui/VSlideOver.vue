<script setup>
/**
 * Right-hand slide panel — used for detail views and wide create/edit forms
 * (companies detail, invoice detail…). Full-screen on mobile.
 */
import { onBeforeUnmount, onMounted, watch } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    titleKey: { type: String, required: true },
    width: { type: String, default: 'md:max-w-2xl' },
});

const emit = defineEmits(['close']);

function onKeydown(event) {
    if (event.key === 'Escape' && props.open) {
        emit('close');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

watch(
    () => props.open,
    (open) => {
        document.documentElement.classList.toggle('overflow-hidden', open);
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
            <div v-if="open" class="fixed inset-0 z-40 bg-black/40" @click.self="emit('close')">
                <transition appear
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="translate-x-full"
                    enter-to-class="translate-x-0">
                    <div role="dialog" aria-modal="true"
                        class="absolute inset-y-0 end-0 flex w-full flex-col overflow-hidden border-s border-line bg-surface-raised shadow-overlay"
                        :class="width">
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
                </transition>
            </div>
        </transition>
    </Teleport>
</template>
