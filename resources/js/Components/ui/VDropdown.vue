<script setup>
/**
 * Generic popover: trigger slot + floating panel. Closes on outside click
 * and Escape. Used by the user menu, apps menu, column pickers, and any
 * custom select.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({
    align: { type: String, default: 'end' }, // start | end
    width: { type: String, default: 'w-56' },
});

const open = ref(false);
const root = ref(null);

function toggle() {
    open.value = !open.value;
}

function close() {
    open.value = false;
}

function onDocumentClick(event) {
    if (open.value && root.value && ! root.value.contains(event.target)) {
        close();
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        close();
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

defineExpose({ close });
</script>

<template>
    <div ref="root" class="relative inline-block">
        <slot name="trigger" :toggle="toggle" :open="open" />
        <transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="scale-95 opacity-0"
            enter-to-class="scale-100 opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="scale-100 opacity-100"
            leave-to-class="scale-95 opacity-0">
            <div v-if="open"
                class="absolute z-30 mt-1.5 origin-top rounded-lg border border-line bg-surface-raised p-1 shadow-raised"
                :class="[width, align === 'end' ? 'end-0' : 'start-0']">
                <slot :close="close" />
            </div>
        </transition>
    </div>
</template>
