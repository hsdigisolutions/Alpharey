<script setup>
/**
 * Drag-and-drop upload zone (documents convention, REQUIREMENTS.md §7).
 * On mobile the same control offers camera capture when `capture` is set.
 * Emits the selected FileList — upload mechanics belong to the caller.
 */
import { ref } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    accept: { type: String, default: null },
    multiple: { type: Boolean, default: false },
    capture: { type: Boolean, default: false }, // offer camera on mobile
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['files']);

const dragging = ref(false);
const input = ref(null);

function onDrop(event) {
    dragging.value = false;
    if (event.dataTransfer?.files?.length) {
        emit('files', event.dataTransfer.files);
    }
}

function onPick(event) {
    if (event.target.files?.length) {
        emit('files', event.target.files);
        event.target.value = '';
    }
}
</script>

<template>
    <div class="rounded-lg border-2 border-dashed p-6 text-center transition-colors duration-150"
        :class="[
            dragging ? 'border-accent bg-accent-soft' : 'border-line bg-surface-sunken/50',
            disabled ? 'pointer-events-none opacity-50' : '',
        ]"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop">
        <AppIcon name="upload" class="mx-auto h-6 w-6 text-muted" />
        <p class="mt-2 text-sm">
            <button type="button" class="font-medium text-accent hover:underline" @click="input.click()">
                <Bilingual k="common.upload_browse" inline />
            </button>
        </p>
        <p class="mt-0.5 text-xs text-muted">
            <Bilingual k="common.upload_drop" class="items-center" />
        </p>
        <button v-if="capture" type="button"
            class="mt-3 inline-flex items-center gap-1.5 rounded-md border border-line bg-surface-raised px-2.5 py-1.5 text-xs font-medium text-ink-soft hover:bg-surface-hover md:hidden"
            @click="$refs.cameraInput.click()">
            <AppIcon name="camera" class="h-3.5 w-3.5" />
            <Bilingual k="common.upload_camera" inline />
        </button>

        <input ref="input" type="file" class="hidden" :accept="accept" :multiple="multiple" @change="onPick" />
        <input v-if="capture" ref="cameraInput" type="file" class="hidden" accept="image/*" capture="environment" @change="onPick" />
    </div>
</template>
