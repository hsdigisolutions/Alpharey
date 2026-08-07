<script setup>
/**
 * Worker PWA — home / check-in screen.
 *
 * The whole flow lives here because it is one decision tree for the worker:
 * "what can I do right now?" — check in (with a selfie + a GPS fix), check
 * out, or report an absence. The screen shows exactly one primary action at a
 * time based on today's state from the server.
 */
import { computed, nextTick, onUnmounted, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { t } from '@/translate';
import { getLocation } from '@/composables/useGeolocation';
import WorkerLayout from '@/Layouts/WorkerLayout.vue';
import SelfieCapture from '@/Components/Worker/SelfieCapture.vue';
import MonthCalendar from '@/Components/Worker/MonthCalendar.vue';
import PrivacyNotice from '@/Components/Worker/PrivacyNotice.vue';
import VButton from '@/Components/ui/VButton.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    worker: { type: Object, required: true },
    today: { type: Object, required: true },
    month: { type: Object, required: true },
    // eslint-disable-next-line vue/prop-name-casing -- Inertia sends snake_case verbatim
    privacy_acknowledged: { type: Boolean, default: true },
    // Feature 3 — advances
    // eslint-disable-next-line vue/prop-name-casing
    pending_advances: { type: Array, default: () => [] },
});

function eur(value) {
    return `${Number(value ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

const page = usePage();
const flashError = computed(() => page.props.flash?.error);

// The month label follows the worker's language (was always Spanish before).
const monthLabel = computed(() => {
    const [y, m] = props.month.month.split('-').map(Number);
    const loc = page.props.locale?.primary === 'en' ? 'en-GB' : 'es-ES';
    return new Date(y, m - 1, 1).toLocaleDateString(loc, { month: 'long', year: 'numeric' });
});

// Hours as "28h 57m" rather than a raw decimal (28.95).
function hoursHM(h) {
    const hh = Math.floor(Math.max(0, Number(h) || 0));
    const mm = Math.round((Math.max(0, Number(h) || 0) - hh) * 60);
    return `${hh}h ${String(mm).padStart(2, '0')}m`;
}

// Live "time worked so far" while checked in — a ticking counter from the
// recorded check-in time, so the worker sees the day accumulate before the
// check-out summary. Only runs while the day is open.
const nowTs = ref(Date.now());
let ticker = null;
if (props.today.state === 'checked_in') {
    ticker = setInterval(() => { nowTs.value = Date.now(); }, 1000);
}
onUnmounted(() => { if (ticker) clearInterval(ticker); });

const workedSoFar = computed(() => {
    if (!props.today.check_in) return hoursHM(0);
    const [hh, mm] = String(props.today.check_in).split(':').map(Number);
    const start = new Date();
    start.setHours(hh, mm, 0, 0);
    const diff = (nowTs.value - start.getTime()) / 3600000;
    return hoursHM(diff > 0 ? diff : 0);
});

// --- Check IN: selfie, then a GPS fix, then submit ---
const camera = ref(null);
const cameraOpen = ref(false);
const cameraFailed = ref(false);
const photoBlob = ref(null);
const busy = ref(false);
const statusLine = ref('');

async function beginCheckIn() {
    cameraFailed.value = false;
    photoBlob.value = null;
    cameraOpen.value = true;
    await nextTick(); // wait for SelfieCapture to mount before calling start()
    await camera.value?.start();
}

function onCaptured(blob) {
    photoBlob.value = blob;
}

function onCameraError() {
    // Camera refused: allow the punch to proceed without a selfie rather than
    // trap the worker. The location flag will still record what happened.
    cameraFailed.value = true;
}

async function submitCheckIn() {
    busy.value = true;
    statusLine.value = t('worker.getting_location');

    // GPS never rejects — a refusal comes back as denied:true (see composable).
    const loc = await getLocation();

    const data = new FormData();
    data.append('lat', loc.lat ?? '');
    data.append('lng', loc.lng ?? '');
    data.append('accuracy', loc.accuracy ?? '');
    data.append('denied', loc.denied ? '1' : '0');
    if (photoBlob.value) data.append('photo', photoBlob.value, 'selfie.jpg');

    router.post('/worker/check-in', data, {
        forceFormData: true,
        onFinish: () => {
            busy.value = false;
            statusLine.value = '';
            cameraOpen.value = false;
        },
    });
}

function openCheckOut() {
    noteTextForm.text_note = '';
    audioBlob.value = null;
    audioDuration.value = null;
    isRecording.value = false;
    checkOutOpen.value = true;
}

async function submitCheckOut() {
    busy.value = true;
    statusLine.value = t('worker.getting_location');
    checkOutOpen.value = false;

    const loc = await getLocation();

    // Capture note data now — page reloads on success and the refs
    // may update; local consts survive the closure.
    const hasNote = !!(noteTextForm.text_note || audioBlob.value);
    const capturedNoteText = noteTextForm.text_note;
    const capturedAudio = audioBlob.value;
    const capturedAudioDuration = audioDuration.value;

    router.post('/worker/check-out', {
        lat: loc.lat,
        lng: loc.lng,
        accuracy: loc.accuracy,
        denied: loc.denied,
    }, {
        onSuccess: () => {
            if (hasNote) {
                const nd = new FormData();
                nd.append('attendance_id', String(props.today.attendance_id));
                if (capturedNoteText) nd.append('text_note', capturedNoteText);
                if (capturedAudio) {
                    nd.append('audio', capturedAudio, 'note.webm');
                    nd.append('duration_seconds', String(capturedAudioDuration ?? 0));
                }
                router.post('/worker/voice-note', nd, { forceFormData: true, preserveScroll: true });
            }
        },
        onFinish: () => {
            busy.value = false;
            statusLine.value = '';
        },
    });
}

// --- Absence ---
const absenceOpen = ref(false);
const absenceForm = useForm({ note: '' });

function submitAbsence() {
    absenceForm.post('/worker/absence', {
        preserveScroll: true,
        onSuccess: () => {
            absenceForm.reset();
            absenceOpen.value = false;
        },
    });
}

// --- Checkout sheet (ask for optional note + expense before submitting) ---
const checkOutOpen = ref(false);
const isRecording = ref(false);
const audioBlob = ref(null);
const audioDuration = ref(null);
let mediaRecorder = null;
let audioChunks = [];
let recordingStart = null;

async function startRecording() {
    audioChunks = [];
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) audioChunks.push(e.data); };
        mediaRecorder.onstop = () => {
            audioBlob.value = new Blob(audioChunks, { type: 'audio/webm' });
            audioDuration.value = Math.round((Date.now() - recordingStart) / 1000);
            stream.getTracks().forEach(t => t.stop());
        };
        recordingStart = Date.now();
        mediaRecorder.start();
        isRecording.value = true;
    } catch (_) {
        // Microphone denied — fall back to text-only.
    }
}

function stopRecording() {
    mediaRecorder?.stop();
    isRecording.value = false;
}

const noteTextForm = useForm({ attendance_id: null, text_note: '', duration_seconds: null });


</script>

<template>
    <Head :title="$t('worker.title')" />

    <WorkerLayout :worker="worker">
        <!-- Geolocation + selfie notice: covers the screen until the worker has
             read it. The server also refuses a punch without it. -->
        <PrivacyNotice v-if="!privacy_acknowledged" />

        <!-- Today's status banner -->
        <div class="mb-4 rounded-lg border border-line bg-surface-raised p-4 text-center shadow-card">
            <p v-if="today.state === 'none'" class="text-sm text-ink-soft">
                {{ $t('worker.status_none') }}
            </p>
            <p v-else-if="today.state === 'checked_in'" class="text-sm font-medium text-status-ok">
                {{ $t('worker.status_checked_in').replace(':time', today.check_in) }}
            </p>
            <p v-else-if="today.state === 'checked_out'" class="text-sm font-medium text-ink">
                {{ $t('worker.status_checked_out').replace(':hours', today.hours ?? 0) }}
            </p>
            <p v-else-if="today.state === 'absent'" class="text-sm font-medium text-status-warn">
                {{ $t('worker.status_absent') }}
            </p>
        </div>

        <p v-if="flashError" class="mb-4 rounded-md bg-status-danger-soft px-3 py-2 text-center text-sm text-status-danger">
            {{ flashError }}
        </p>

        <!-- STATE: nothing yet today → check in (or report absence) -->
        <template v-if="today.state === 'none'">
            <div v-if="!cameraOpen" class="space-y-3">
                <VButton class="w-full rounded-xl text-lg font-semibold" size="lg" @click="beginCheckIn">
                    {{ $t('worker.check_in') }}
                </VButton>
                <VButton variant="danger" size="lg" class="w-full rounded-xl" @click="absenceOpen = true">
                    {{ $t('worker.report_absence') }}
                </VButton>
            </div>

            <!-- Selfie step -->
            <div v-else class="space-y-3">
                <p class="text-center text-sm"
                    :class="cameraFailed ? 'text-status-warn' : 'text-ink-soft'">
                    {{ cameraFailed ? $t('worker.camera_denied') : $t('worker.camera_prompt') }}
                </p>

                <SelfieCapture v-if="!cameraFailed" ref="camera" @captured="onCaptured" @error="onCameraError" />

                <VButton v-if="!photoBlob && !cameraFailed" class="w-full" size="lg" @click="camera.capture()">
                    {{ $t('worker.take_photo') }}
                </VButton>

                <template v-else>
                    <p v-if="statusLine" class="text-center text-xs text-muted">{{ statusLine }}</p>
                    <VButton class="w-full" size="lg" :loading="busy" @click="submitCheckIn">
                        {{ $t('worker.check_in') }}
                    </VButton>
                    <button v-if="!cameraFailed" type="button" class="w-full py-2 text-sm text-ink-soft"
                        @click="beginCheckIn">
                        {{ $t('worker.retake') }}
                    </button>
                </template>
            </div>
        </template>

        <!-- STATE: checked in → confirmation card + live counter, then check out -->
        <template v-else-if="today.state === 'checked_in'">
            <div class="mb-4 rounded-lg border border-status-ok/40 bg-status-ok-soft p-4 shadow-card">
                <div class="mb-3 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-status-ok text-xs text-on-accent">✓</span>
                    <span class="text-sm font-semibold text-status-ok">{{ $t('worker.checkin_confirmed') }}</span>
                </div>
                <dl class="space-y-1.5 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.checkin_time') }}</dt>
                        <dd class="tabular-nums font-medium text-ink">{{ today.check_in }}</dd>
                    </div>
                    <div v-if="today.project" class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.detail_project') }}</dt>
                        <dd class="font-medium text-ink">{{ today.project }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.worked_so_far') }}</dt>
                        <dd class="tabular-nums font-semibold text-status-ok">{{ workedSoFar }}</dd>
                    </div>
                </dl>
                <p v-if="today.location_captured" class="mt-3 flex items-center gap-1.5 text-xs text-status-ok">
                    <span>📍</span>{{ $t('worker.location_captured') }}
                </p>
                <p v-else class="mt-3 flex items-center gap-1.5 rounded-md bg-status-warn-soft px-2 py-1.5 text-xs text-status-warn">
                    <span>⚠️</span>{{ $t('worker.location_not_captured') }}
                </p>
            </div>

            <p v-if="statusLine" class="mb-2 text-center text-xs text-muted">{{ statusLine }}</p>
            <VButton variant="secondary" class="w-full" size="lg" :loading="busy" @click="openCheckOut">
                {{ $t('worker.check_out') }}
            </VButton>
        </template>

        <!-- STATE: checked out → the closed-day summary with the day's total -->
        <div v-else-if="today.state === 'checked_out'" class="space-y-3">
            <div class="rounded-lg border border-line bg-surface-raised p-4 shadow-card">
                <p class="mb-3 text-center text-sm font-semibold text-ink">{{ $t('worker.checkout_summary') }}</p>
                <dl class="space-y-1.5 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.summary_entry') }}</dt>
                        <dd class="tabular-nums font-medium text-ink">{{ today.check_in }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.summary_exit') }}</dt>
                        <dd class="tabular-nums font-medium text-ink">{{ today.check_out }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.summary_hours') }}</dt>
                        <dd class="tabular-nums font-medium text-ink">{{ hoursHM(today.hours) }}</dd>
                    </div>
                    <div v-if="today.project" class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.detail_project') }}</dt>
                        <dd class="font-medium text-ink">{{ today.project }}</dd>
                    </div>
                    <div v-if="today.amount !== null" class="mt-1 flex items-center justify-between border-t border-line pt-2">
                        <dt class="font-medium text-accent">{{ $t('worker.summary_amount') }}</dt>
                        <dd class="tabular-nums text-lg font-semibold text-accent">{{ eur(today.amount) }}</dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-lg border border-line bg-surface-raised p-4 text-center text-sm text-ink-soft shadow-card">
                {{ $t('worker.done_for_today') }}
            </div>
        </div>

        <!-- STATE: absent → nothing more to do today -->
        <div v-else class="space-y-3">
            <div class="rounded-lg border border-line bg-surface-raised p-5 text-center text-sm text-ink-soft shadow-card">
                {{ $t('worker.done_for_today') }}
            </div>
        </div>

        <!-- Feature 4: Vehicle link -->
        <div v-if="worker.can_use_vehicles" class="mt-4">
            <a href="/worker/vehicles"
                class="flex items-center justify-between rounded-lg border border-line bg-surface-raised px-4 py-3 shadow-card hover:bg-surface-hover">
                <span class="text-sm font-medium text-ink">{{ $t('worker_vehicles.title') }}</span>
                <span class="text-xs text-ink-soft">›</span>
            </a>
        </div>

        <!-- Feature 3: Pending advances panel -->
        <div v-if="pending_advances.length" class="mt-4 rounded-lg border border-line bg-status-warn-soft p-4 shadow-card">
            <p class="mb-2 text-sm font-semibold text-status-warn">{{ $t('worker.advances_title') }}</p>
            <ul class="space-y-1">
                <li v-for="adv in pending_advances" :key="adv.payroll_month"
                    class="flex items-center justify-between text-sm">
                    <span class="text-ink-soft">{{ adv.payroll_month ?? '—' }}</span>
                    <span class="tabular-nums font-medium text-status-warn">{{ eur(adv.amount) }}</span>
                </li>
            </ul>
        </div>

        <!-- ── Dashboard: this month ── -->
        <section class="mt-6">
            <h2 class="mb-3 text-sm font-semibold capitalize text-ink">{{ monthLabel }}</h2>

            <!-- Summary figures -->
            <div class="mb-3 grid grid-cols-3 gap-2">
                <div class="rounded-lg border border-line bg-surface-raised p-3 text-center shadow-card">
                    <p class="tabular-nums text-2xl font-semibold text-status-ok">{{ month.present }}</p>
                    <p class="text-xs text-ink-soft">{{ $t('worker.days_present') }}</p>
                </div>
                <div class="rounded-lg border border-line bg-surface-raised p-3 text-center shadow-card">
                    <p class="tabular-nums text-2xl font-semibold text-status-danger">{{ month.absent }}</p>
                    <p class="text-xs text-ink-soft">{{ $t('worker.days_absent') }}</p>
                </div>
                <div class="rounded-lg border border-line bg-surface-raised p-3 text-center shadow-card">
                    <p class="tabular-nums text-lg font-semibold text-ink">{{ hoursHM(month.hours) }}</p>
                    <p class="text-xs text-ink-soft">{{ $t('worker.total_hours') }}</p>
                </div>
            </div>

            <!-- Salary earned this month -->
            <div class="mb-4 flex items-center justify-between rounded-lg border border-accent/40 bg-accent-soft px-4 py-3 shadow-card">
                <span class="text-sm font-medium text-accent">{{ $t('worker.month_earned') }}</span>
                <span class="tabular-nums text-xl font-semibold text-accent">{{ eur(month.earned) }}</span>
            </div>

            <!-- Calendar -->
            <div class="rounded-lg border border-line bg-surface-raised p-3 shadow-card">
                <MonthCalendar :month="month" />
                <div class="mt-3 flex flex-wrap justify-center gap-x-4 gap-y-1 border-t border-line pt-3 text-[11px] text-ink-soft">
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-status-ok" />{{ $t('worker.legend_present') }}</span>
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-status-danger" />{{ $t('worker.legend_absent') }}</span>
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-surface-sunken ring-1 ring-line" />{{ $t('worker.legend_none') }}</span>
                </div>
            </div>
        </section>

        <!-- Absence sheet -->
        <div v-if="absenceOpen" class="fixed inset-0 z-40 flex items-end bg-black/40" @click.self="absenceOpen = false">
            <div class="w-full rounded-t-xl bg-surface-raised p-5 shadow-overlay"
                style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                <h2 class="mb-3 text-base font-semibold">{{ $t('worker.absence_reason') }}</h2>
                <form @submit.prevent="submitAbsence">
                    <VTextarea v-model="absenceForm.note" :rows="3" :placeholder="$t('worker.absence_placeholder')" />
                    <p v-if="absenceForm.errors.note" class="mt-1 text-xs text-status-danger">{{ absenceForm.errors.note }}</p>
                    <div class="mt-4 flex gap-2">
                        <VButton variant="ghost" class="flex-1" type="button" @click="absenceOpen = false">
                            {{ $t('common.cancel') }}
                        </VButton>
                        <VButton class="flex-1" type="submit" :loading="absenceForm.processing">
                            {{ $t('worker.absence_submit') }}
                        </VButton>
                    </div>
                </form>
            </div>
        </div>

        <!-- Checkout sheet: optional note + optional expense, then submit -->
        <div v-if="checkOutOpen" class="fixed inset-0 z-40 flex items-end bg-black/40" @click.self="checkOutOpen = false">
            <div class="w-full rounded-t-xl bg-surface-raised shadow-overlay"
                style="max-height: 88vh; overflow-y: auto; padding: 1.25rem; padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                <h2 class="mb-4 text-base font-semibold">{{ $t('worker.check_out') }}</h2>

                <!-- Day summary before confirming: what the worker is closing out. -->
                <dl class="mb-5 space-y-1.5 rounded-lg border border-line bg-surface-sunken p-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.summary_entry') }}</dt>
                        <dd class="tabular-nums font-medium text-ink">{{ today.check_in }}</dd>
                    </div>
                    <div v-if="today.project" class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.detail_project') }}</dt>
                        <dd class="font-medium text-ink">{{ today.project }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-soft">{{ $t('worker.worked_so_far') }}</dt>
                        <dd class="tabular-nums font-semibold text-accent">{{ workedSoFar }}</dd>
                    </div>
                </dl>

                <!-- Note section -->
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('worker.voice_note') }}</p>
                <div class="mb-3 flex flex-col items-center gap-2">
                    <div v-if="audioBlob" class="w-full rounded-md bg-status-ok-soft px-3 py-2 text-sm text-status-ok">
                        {{ $t('worker.note_recorded').replace(':s', audioDuration ?? 0) }}
                    </div>
                    <template v-else>
                        <div class="flex items-center gap-3">
                            <button v-if="!isRecording" type="button"
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-status-danger text-on-accent shadow-raised"
                                @click="startRecording">
                                <span class="h-3.5 w-3.5 rounded-full bg-white" />
                            </button>
                            <button v-else type="button"
                                class="flex h-12 w-12 animate-pulse items-center justify-center rounded-full bg-status-danger text-on-accent shadow-raised"
                                @click="stopRecording">
                                <span class="h-3 w-3 rounded-sm bg-white" />
                            </button>
                            <p class="text-xs text-ink-soft">
                                {{ isRecording ? $t('worker.note_recording') : $t('worker.note_tap_record') }}
                            </p>
                        </div>
                    </template>
                </div>
                <VTextarea v-model="noteTextForm.text_note" :rows="2" :placeholder="$t('worker.note_text_placeholder')" class="mb-4" />

                <div class="flex gap-2">
                    <VButton variant="ghost" class="flex-1" type="button" @click="checkOutOpen = false">
                        {{ $t('common.cancel') }}
                    </VButton>
                    <VButton variant="secondary" class="flex-1" :loading="busy" @click="submitCheckOut">
                        {{ $t('worker.confirm_check_out') }}
                    </VButton>
                </div>
            </div>
        </div>
    </WorkerLayout>
</template>
