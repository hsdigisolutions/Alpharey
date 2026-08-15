<script setup>
/**
 * Worker PWA voice recorder — MediaRecorder (audio/webm), works on mobile
 * Safari + Chrome. v-model carries `{ blob, duration }` (seconds) or null.
 *
 * States: idle (mic + prompt) → recording (pulsing indicator + live mm:ss +
 * Stop, hard-capped at maxSeconds) → recorded (playback + Delete to re-record).
 * A denied microphone simply leaves it idle — the voice note is always optional.
 */
import { computed, onUnmounted, ref } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    modelValue: { type: Object, default: null }, // { blob, duration } | null
    maxSeconds: { type: Number, default: 120 },
});
const emit = defineEmits(['update:modelValue']);

const isRecording = ref(false);
const elapsed = ref(0);
const audioUrl = ref(null);
let mediaRecorder = null;
let chunks = [];
let startedAt = null;
let timer = null;

function fmt(s) {
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}
const timerLabel = computed(() => fmt(isRecording.value ? elapsed.value : (props.modelValue?.duration ?? 0)));
const maxLabel = computed(() => fmt(props.maxSeconds));

async function start() {
    chunks = [];
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) chunks.push(e.data); };
        mediaRecorder.onstop = () => {
            const blob = new Blob(chunks, { type: 'audio/webm' });
            const duration = Math.min(props.maxSeconds, Math.max(1, Math.round((Date.now() - startedAt) / 1000)));
            if (audioUrl.value) URL.revokeObjectURL(audioUrl.value);
            audioUrl.value = URL.createObjectURL(blob);
            emit('update:modelValue', { blob, duration });
            stream.getTracks().forEach((t) => t.stop());
        };
        startedAt = Date.now();
        elapsed.value = 0;
        timer = setInterval(() => {
            elapsed.value = Math.round((Date.now() - startedAt) / 1000);
            if (elapsed.value >= props.maxSeconds) stop(); // hard cap (2 min)
        }, 250);
        mediaRecorder.start();
        isRecording.value = true;
    } catch { /* microphone denied — stay idle, the note is optional */ }
}

function stop() {
    if (!isRecording.value) return;
    mediaRecorder?.stop();
    isRecording.value = false;
    if (timer) { clearInterval(timer); timer = null; }
}

function remove() {
    if (audioUrl.value) { URL.revokeObjectURL(audioUrl.value); audioUrl.value = null; }
    emit('update:modelValue', null);
}

onUnmounted(() => {
    if (timer) clearInterval(timer);
    if (audioUrl.value) URL.revokeObjectURL(audioUrl.value);
    if (isRecording.value) mediaRecorder?.stop();
});
</script>

<template>
    <!-- Recording: pulsing indicator + running timer + stop -->
    <button v-if="isRecording" type="button" @click="stop"
        class="flex w-full items-center gap-3 rounded-lg border border-status-danger bg-status-danger-soft px-3 py-3 text-start">
        <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-status-danger text-white">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-danger opacity-60" />
            <span class="relative h-3 w-3 rounded-sm bg-white" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-status-danger">{{ $t('voice.stop') }}</span>
            <span class="tabular-nums block text-xs text-status-danger/80">{{ timerLabel }} / {{ maxLabel }}</span>
        </span>
    </button>

    <!-- Recorded: playback + delete -->
    <div v-else-if="modelValue"
        class="flex items-center gap-2 rounded-lg border border-status-ok/40 bg-status-ok-soft px-3 py-2">
        <audio :src="audioUrl" controls preload="metadata" class="h-9 min-w-0 flex-1"></audio>
        <button type="button" class="shrink-0 px-1 text-sm font-medium text-status-danger hover:underline" @click="remove">
            {{ $t('voice.delete') }}
        </button>
    </div>

    <!-- Idle: mic + prompt -->
    <button v-else type="button" @click="start"
        class="flex w-full items-center gap-3 rounded-lg border border-line bg-surface-sunken px-3 py-3 text-start hover:bg-surface-hover">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-status-danger text-white">
            <AppIcon name="mic" class="h-5 w-5" />
        </span>
        <span class="text-sm font-medium text-ink">{{ $t('voice.record') }}</span>
    </button>
</template>
