<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    message: { type: String, default: '' },
});

const emit = defineEmits(['confirm', 'cancel']);

function onKeydown(e) {
    if (e.key === 'Escape' && props.open) emit('cancel');
}
onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0">
            <div v-if="open"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                @click.self="emit('cancel')">
                <div role="alertdialog" aria-modal="true"
                    class="w-full max-w-sm rounded-xl border border-line bg-surface-raised shadow-overlay">
                    <div class="flex flex-col items-center gap-3 px-6 pt-6 pb-4 text-center">
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-status-danger-soft">
                            <AppIcon name="trash" class="h-5 w-5 text-status-danger" />
                        </span>
                        <div>
                            <p class="text-base font-semibold text-ink">
                                <Bilingual k="common.confirm_delete_title" />
                            </p>
                            <p v-if="message" class="mt-1 text-sm font-medium text-ink">{{ message }}</p>
                            <p class="mt-1 text-sm text-ink-soft">
                                <Bilingual k="common.confirm_delete_body" />
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-2 border-t border-line px-6 py-4">
                        <button type="button"
                            class="flex-1 rounded-md border border-line-strong bg-surface px-4 py-2 text-sm font-medium text-ink hover:bg-surface-hover"
                            @click="emit('cancel')">
                            <Bilingual k="common.cancel" inline />
                        </button>
                        <button type="button"
                            class="flex-1 rounded-md bg-status-danger px-4 py-2 text-sm font-medium text-white hover:opacity-90"
                            @click="emit('confirm')">
                            <Bilingual k="common.delete" inline />
                        </button>
                    </div>
                </div>
            </div>
        </transition>
    </Teleport>
</template>
