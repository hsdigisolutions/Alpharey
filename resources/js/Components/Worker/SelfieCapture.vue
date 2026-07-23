<script setup>
/**
 * Selfie capture for check-in.
 *
 * Uses getUserMedia (front camera) rather than a file <input capture>, so the
 * worker cannot pick an old photo from the gallery — the point is a live face
 * at the moment of the punch. The frame is drawn to a canvas and exported as a
 * compressed JPEG (~long edge 720px, quality 0.7) so a 4MB phone photo becomes
 * ~150KB before it ever touches the site's connection.
 *
 * The stream is stopped the instant a shot is taken or the component unmounts —
 * leaving the camera light on would rightly alarm a worker.
 */
import { onBeforeUnmount, ref } from 'vue';

const emit = defineEmits(['captured', 'error']);

const video = ref(null);
const stream = ref(null);
const active = ref(false);
const preview = ref(null);

const MAX_EDGE = 720;
const QUALITY = 0.7;

async function start() {
    preview.value = null;

    try {
        stream.value = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1280 } },
            audio: false,
        });
        video.value.srcObject = stream.value;
        await video.value.play();
        active.value = true;
    } catch {
        // Permission refused or no camera. The screen offers the worker a way
        // forward rather than a dead end.
        emit('error');
    }
}

function stop() {
    stream.value?.getTracks().forEach((track) => track.stop());
    stream.value = null;
    active.value = false;
}

function capture() {
    const v = video.value;
    const scale = Math.min(1, MAX_EDGE / Math.max(v.videoWidth, v.videoHeight));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(v.videoWidth * scale);
    canvas.height = Math.round(v.videoHeight * scale);
    canvas.getContext('2d').drawImage(v, 0, 0, canvas.width, canvas.height);

    preview.value = canvas.toDataURL('image/jpeg', QUALITY);

    canvas.toBlob(
        (blob) => {
            stop();
            emit('captured', blob, preview.value);
        },
        'image/jpeg',
        QUALITY,
    );
}

onBeforeUnmount(stop);

defineExpose({ start, stop });
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-line bg-surface-sunken">
        <!-- Live preview (mirrored, as a selfie camera should be) -->
        <video v-show="active && !preview" ref="video" class="aspect-square w-full -scale-x-100 object-cover" playsinline muted />

        <!-- Captured still -->
        <img v-if="preview" :src="preview" alt="" class="aspect-square w-full object-cover" />

        <!-- Placeholder before the camera starts -->
        <div v-if="!active && !preview" class="flex aspect-square w-full items-center justify-center text-muted">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-12 w-12">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                <circle cx="12" cy="13" r="4" />
            </svg>
        </div>
    </div>
</template>
