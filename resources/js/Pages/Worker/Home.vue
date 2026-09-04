<script setup>
/**
 * Worker PWA — home / check-in screen.
 *
 * The whole flow lives here because it is one decision tree for the worker:
 * "what can I do right now?" — check in (with a selfie + a GPS fix), check
 * out, or report an absence. The screen shows exactly one primary action at a
 * time based on today's state from the server.
 */
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { t } from '@/translate';
import { getLocation } from '@/composables/useGeolocation';
import WorkerLayout from '@/Layouts/WorkerLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import SelfieCapture from '@/Components/Worker/SelfieCapture.vue';
import VoiceRecorder from '@/Components/Worker/VoiceRecorder.vue';
import MonthCalendar from '@/Components/Worker/MonthCalendar.vue';
import PrivacyNotice from '@/Components/Worker/PrivacyNotice.vue';
import VButton from '@/Components/ui/VButton.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    worker: { type: Object, required: true },
    today: { type: Object, required: true },
    month: { type: Object, required: true },
    // Weekend gating: { is_weekend, rest_day, offer:{project,rate_type}|null }
    weekend: { type: Object, default: () => ({ is_weekend: false, rest_day: false, offer: null }) },
    // eslint-disable-next-line vue/prop-name-casing -- Inertia sends snake_case verbatim
    privacy_acknowledged: { type: Boolean, default: true },
    // Consent state: { gps, photo, version } — gates GPS/selfie capture.
    consent: { type: Object, default: () => ({ gps: true, photo: true, version: '' }) },
    // Worker-direct notifications (PWA bell): { unread, items[] }
    notifications: { type: Object, default: () => ({ unread: 0, items: [] }) },
    equipment: { type: Array, default: () => [] },
    // Active projects this worker may punch into (own + deployed). Coords only —
    // no money. Drives the check-in project picker + on-device distance hint.
    assignedProjects: { type: Array, default: () => [] },
    // Projects GPS auto-detect can assign (own company + deployed, with coords) —
    // drives the pre-punch "You're at [Project] ✓" confirmation. Coords/name only.
    detectableProjects: { type: Array, default: () => [] },
});

const page = usePage();
const flashError = computed(() => page.props.flash?.error);
const flashWarning = computed(() => page.props.flash?.warning);

// ── PWA notification bell ────────────────────────────────────────────────────
const notifOpen = ref(false);
const notifItems = computed(() => props.notifications?.items ?? []);
const notifUnread = computed(() => props.notifications?.unread ?? 0);

function openNotification(item) {
    router.post(`/worker/notifications/${item.id}/read`, {}, {
        preserveScroll: !item.url,
        preserveState: !item.url,
        onSuccess: () => { if (item.url && item.url !== '/worker') router.visit(item.url); },
    });
}

function markAllNotificationsRead() {
    router.post('/worker/notifications/read-all', {}, { preserveScroll: true, preserveState: false });
}

// The month label follows the worker's language (was always Spanish before).
const monthLabel = computed(() => {
    const [y, m] = props.month.month.split('-').map(Number);
    const loc = page.props.locale?.primary === 'en' ? 'en-GB' : 'es-ES';
    return new Date(y, m - 1, 1).toLocaleDateString(loc, { month: 'long', year: 'numeric' });
});

// The weekend offer's rate, shown by its actual type (normal / ×1.5 / ×2 /
// especial) — not a blanket "special rate".
const weekendRateLabel = computed(() => ({
    normal: t('attendance.offer_rate_normal'),
    'x1.5': t('attendance.offer_rate_x15'),
    'x2': t('attendance.offer_rate_x2'),
    custom: t('attendance.offer_rate_custom'),
}[props.weekend.offer?.rate_type] ?? ''));

// --- Project selection for check-in --------------------------------------
// The worker picks which site they're punching into. One project → locked and
// auto-selected; several → a picker, nearest-first once a fix lands. GPS is a
// hint here (distance + ordering), NEVER a gate — the punch always stands.
const assignedProjects = computed(() => props.assignedProjects ?? []);
const deviceLoc = ref(null); // best-effort fix for nearest-first + distance hint

function haversineM(lat1, lng1, lat2, lng2) {
    const R = 6371000;
    const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(a));
}

function projectDistance(p) {
    if (!deviceLoc.value || p?.latitude == null || p?.longitude == null) return null;
    return haversineM(deviceLoc.value.lat, deviceLoc.value.lng, p.latitude, p.longitude);
}

// Nearest-first when a device fix is available; else the server (name) order.
const sortedProjects = computed(() => {
    const list = [...assignedProjects.value];
    if (!deviceLoc.value) return list;
    return list.sort((a, b) => {
        const da = projectDistance(a);
        const db = projectDistance(b);
        if (da == null) return 1;
        if (db == null) return -1;
        return da - db;
    });
});

const selectedProjectId = ref(assignedProjects.value.length === 1 ? assignedProjects.value[0].id : null);
// VSelect emits the option value as a STRING (DOM option values always are), so
// compare loosely — otherwise a picked project (string id) never matches the
// numeric p.id and the distance hint / Navigate link silently disappear.
const selectedProject = computed(() => {
    if (selectedProjectId.value == null || selectedProjectId.value === '') return null;
    return assignedProjects.value.find((p) => String(p.id) === String(selectedProjectId.value)) ?? null;
});
const selectedDistance = computed(() => (selectedProject.value ? projectDistance(selectedProject.value) : null));

function fmtDistance(m) {
    if (m == null) return '';
    return m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${Math.round(m)} m`;
}

// Beyond the site's own radius (default 500 m) → a soft "confirm you're here"
// hint. Advisory only; it never blocks the punch.
const distanceWarn = computed(() => {
    const p = selectedProject.value;
    const d = selectedDistance.value;
    return Boolean(p && d != null && d >= (p.geofence_radius ?? 500));
});

function mapsUrl(p) {
    if (!p || p.latitude == null || p.longitude == null) return null;
    return `https://www.google.com/maps/dir/?api=1&destination=${p.latitude},${p.longitude}`;
}

// GPS auto-assign PREVIEW: when the worker hasn't picked a project, the nearest
// detectable project (own company + deployed) their fix is inside — the same
// one the server will auto-assign on check-in. Nearest wins on overlap.
const detectableProjects = computed(() => props.detectableProjects ?? []);
const autoDetectedProject = computed(() => {
    if (selectedProjectId.value != null && selectedProjectId.value !== '') return null;
    if (!deviceLoc.value) return null;
    let best = null;
    let bestD = null;
    for (const p of detectableProjects.value) {
        if (p.latitude == null || p.longitude == null) continue;
        const d = haversineM(deviceLoc.value.lat, deviceLoc.value.lng, p.latitude, p.longitude);
        const threshold = p.geofence_radius > 0 ? p.geofence_radius : 150;
        if (d <= threshold && (bestD == null || d < bestD)) {
            bestD = d;
            best = p;
        }
    }
    return best;
});

// Best-effort silent fix on load — only to order the picker and show a distance
// hint; the punch fetches its own fix. A refusal just leaves name order (GPS is
// evidence, not a gate). Skipped when no project carries coordinates.
onMounted(async () => {
    if (!props.consent.gps) return;
    // Only when a check-in is actually possible — don't prompt for GPS on a day
    // that's already checked in / out, or on a weekend rest day.
    if (props.today.state !== 'none' || props.weekend.rest_day) return;
    const anyCoords = assignedProjects.value.some((p) => p.latitude != null && p.longitude != null)
        || detectableProjects.value.some((p) => p.latitude != null && p.longitude != null);
    if (!anyCoords) return;
    try {
        const loc = await getLocation();
        if (loc && loc.lat != null && loc.lng != null) {
            deviceLoc.value = { lat: loc.lat, lng: loc.lng };
            if (selectedProjectId.value == null && sortedProjects.value.length) {
                selectedProjectId.value = sortedProjects.value[0].id;
            }
        }
    } catch { /* GPS optional — evidence, not a gate */ }
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

// Bell without a WebSocket daemon (the cPanel host can't run one): poll the
// notifications prop every 60 s via an Inertia partial reload. Pauses while the
// tab is hidden. The 'worker' prop stays fresh too — cheap partial reload.
let bellTimer = setInterval(() => {
    if (document.visibilityState === 'visible') {
        router.reload({ only: ['notifications'] });
    }
}, 60000);

onUnmounted(() => {
    if (ticker) clearInterval(ticker);
    if (bellTimer) clearInterval(bellTimer);
    if (recordingTimer) clearInterval(recordingTimer);
    // Release any check-out photo preview object URLs.
    Object.values(photoPreviews.value).forEach((url) => url && URL.revokeObjectURL(url));
});

// Live worked hours (decimal) since check-in — the source for both the "Hours"
// readout and the check-out button state.
const workedHours = computed(() => {
    // Use the absolute check-in instant (UTC/ISO) so the elapsed time is correct
    // regardless of the phone's timezone. Falls back to the "HH:mm" label only if
    // the timestamp is missing (legacy rows).
    let startMs = null;
    if (props.today.check_in_at) {
        startMs = Date.parse(props.today.check_in_at);
    } else if (props.today.check_in) {
        const [hh, mm] = String(props.today.check_in).split(':').map(Number);
        const s = new Date();
        s.setHours(hh, mm, 0, 0);
        startMs = s.getTime();
    }
    if (startMs === null || Number.isNaN(startMs)) return 0;
    const diff = (nowTs.value - startMs) / 3600000;
    return diff > 0 ? diff : 0;
});

const workedSoFar = computed(() => hoursHM(workedHours.value));

// Has the worker put in a full day? Drives the check-out button colour:
// green once the full-day threshold is met, amber (a warning) before that.
const dayComplete = computed(() => workedHours.value >= (props.today.full_day_threshold ?? 8));

// --- Check IN: selfie, then a GPS fix, then submit ---
const camera = ref(null);
const cameraOpen = ref(false);
const cameraFailed = ref(false);
const photoBlob = ref(null);
const busy = ref(false);
const statusLine = ref('');
// A check-in failure must never be silent — the worker sees why (before this,
// a rejected punch just reset the screen with no feedback).
const checkInError = ref('');

async function beginCheckIn() {
    checkInError.value = '';
    // Selfie consent withheld → skip the camera step entirely and punch in.
    if (!props.consent.photo) {
        submitCheckIn();
        return;
    }
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

    // GPS only when consent is given; otherwise punch with no location.
    let loc = { lat: null, lng: null, accuracy: null, denied: false };
    if (props.consent.gps) {
        statusLine.value = t('worker.getting_location');
        loc = await getLocation();
    }

    const data = new FormData();
    data.append('lat', loc.lat ?? '');
    data.append('lng', loc.lng ?? '');
    data.append('accuracy', loc.accuracy ?? '');
    data.append('denied', loc.denied ? '1' : '0');
    // The chosen site (weekday only; a weekend offer locks it server-side). A
    // project-less punch is allowed, so send nothing when none is selected.
    if (selectedProjectId.value != null && selectedProjectId.value !== '') {
        data.append('project_id', String(selectedProjectId.value));
    }
    if (props.consent.photo && photoBlob.value) data.append('photo', photoBlob.value, 'selfie.jpg');

    router.post('/worker/check-in', data, {
        forceFormData: true,
        onError: (errors) => {
            // Show the first server error instead of silently resetting.
            checkInError.value = Object.values(errors)[0] ?? t('worker.checkin_failed');
        },
        onFinish: () => {
            busy.value = false;
            statusLine.value = '';
            cameraOpen.value = false;
        },
    });
}

function openCheckOut() {
    noteTextForm.text_note = '';
    voiceNote.value = null;
    resetCheckoutPhotos();
    checkOutOpen.value = true;
}

async function submitCheckOut() {
    // The proof-of-work attachment is required — the server enforces it too.
    if (!attachmentFile.value) return;

    busy.value = true;
    checkOutOpen.value = false;

    // GPS only when consent is given.
    let loc = { lat: null, lng: null, accuracy: null, denied: false };
    if (props.consent.gps) {
        statusLine.value = t('worker.getting_location');
        loc = await getLocation();
    }

    // Capture note data now — page reloads on success and the refs
    // may update; local consts survive the closure.
    const hasNote = !!(noteTextForm.text_note || voiceNote.value?.blob);
    const capturedNoteText = noteTextForm.text_note;
    const capturedAudio = voiceNote.value?.blob ?? null;
    const capturedAudioDuration = voiceNote.value?.duration ?? null;

    const data = new FormData();
    data.append('lat', loc.lat ?? '');
    data.append('lng', loc.lng ?? '');
    data.append('accuracy', loc.accuracy ?? '');
    data.append('denied', loc.denied ? '1' : '0');
    data.append('work_attachment', attachmentFile.value);
    if (photo2File.value) data.append('work_attachment_2', photo2File.value);
    if (photo3File.value) data.append('work_attachment_3', photo3File.value);

    router.post('/worker/check-out', data, {
        forceFormData: true,
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
// The voice note is now the shared <VoiceRecorder> component: { blob, duration } | null.
const voiceNote = ref(null);

// Proof-of-work photos at check-out: photo 1 required (site photo or document),
// photos 2 & 3 optional extra site photos. Each can be retaken or deleted.
const attachmentFile = ref(null); // photo 1
const photo2File = ref(null);
const photo3File = ref(null);
const photoPreviews = ref({ 1: null, 2: null, 3: null });
const attachmentName = computed(() => attachmentFile.value?.name ?? '');

function photoSlot(slot) {
    return slot === 1 ? attachmentFile : slot === 2 ? photo2File : photo3File;
}
function setPhoto(slot, file) {
    if (photoPreviews.value[slot]) {
        URL.revokeObjectURL(photoPreviews.value[slot]);
        photoPreviews.value[slot] = null;
    }
    photoSlot(slot).value = file ?? null;
    if (file && file.type?.startsWith('image/')) {
        photoPreviews.value[slot] = URL.createObjectURL(file);
    }
}
function onPhotoChange(slot, e) {
    setPhoto(slot, e.target.files?.[0] ?? null);
    e.target.value = ''; // allow re-picking the same file (retake)
}
function removePhoto(slot) {
    // Higher slots are revealed only after the lower one is taken, so clearing a
    // slot also clears the ones that depend on it — otherwise a hidden photo
    // would still be submitted.
    for (let s = slot; s <= 3; s++) setPhoto(s, null);
}
function resetCheckoutPhotos() {
    [1, 2, 3].forEach((s) => setPhoto(s, null));
}

const noteTextForm = useForm({ attendance_id: null, text_note: '', duration_seconds: null });


</script>

<template>
    <Head :title="$t('worker.title')" />

    <WorkerLayout :worker="worker">
        <!-- Geolocation + selfie notice: covers the screen until the worker has
             read it. The server also refuses a punch without it. -->
        <PrivacyNotice v-if="!privacy_acknowledged" />

        <!-- Today's status banner. Hidden once checked out — the day summary
             card below already tells the whole story. -->
        <div v-if="today.state !== 'checked_out' && !weekend.rest_day" class="mb-4 rounded-lg border border-line bg-surface-raised p-4 text-center shadow-card">
            <p v-if="today.state === 'none'" class="text-sm text-ink-soft">
                {{ $t('worker.status_none') }}
            </p>
            <p v-else-if="today.state === 'checked_in'" class="text-sm font-medium text-status-ok">
                {{ $t('worker.status_checked_in').replace(':time', today.check_in) }}
            </p>
            <p v-else-if="today.state === 'absent'" class="text-sm font-medium text-status-warn">
                {{ $t('worker.status_absent') }}
            </p>
        </div>

        <!-- Notification bell: advance / expense / leave decided, weekend offer -->
        <div v-if="notifItems.length" class="mb-4 rounded-lg border border-line bg-surface-raised shadow-card">
            <button type="button" class="flex w-full items-center justify-between px-4 py-3"
                @click="notifOpen = !notifOpen">
                <span class="flex items-center gap-2 text-sm font-medium text-ink">
                    <AppIcon name="bell" class="h-4 w-4 text-ink-soft" />
                    {{ $t('worker.notifications') }}
                    <span v-if="notifUnread > 0"
                        class="tabular-nums inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-status-danger px-1.5 text-[11px] font-semibold text-white">
                        {{ notifUnread }}
                    </span>
                </span>
                <span class="text-xs text-muted">{{ notifOpen ? '▲' : '▼' }}</span>
            </button>
            <div v-if="notifOpen" class="border-t border-line">
                <button v-for="item in notifItems" :key="item.id" type="button"
                    class="flex w-full items-start gap-3 border-b border-line px-4 py-3 text-start last:border-0"
                    :class="{ 'bg-accent-soft/40': !item.read }"
                    @click="openNotification(item)">
                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-ink-soft">
                        <AppIcon :name="item.icon" class="h-4 w-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm leading-snug text-ink">{{ item.title }}</span>
                        <span v-if="item.body" class="mt-0.5 block text-xs leading-snug text-ink-soft">{{ item.body }}</span>
                        <span class="mt-0.5 block text-[11px] text-faint">{{ item.created_at }}</span>
                    </span>
                    <span v-if="!item.read" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-accent" />
                </button>
                <button v-if="notifUnread > 0" type="button"
                    class="w-full py-2.5 text-center text-xs font-medium text-accent-hover"
                    @click="markAllNotificationsRead">
                    {{ $t('worker.notifications_mark_all') }}
                </button>
            </div>
        </div>

        <p v-if="flashError" class="mb-4 rounded-md bg-status-danger-soft px-3 py-2 text-center text-sm text-status-danger">
            {{ flashError }}
        </p>
        <p v-if="flashWarning" class="mb-4 flex items-center justify-center gap-1.5 rounded-md bg-status-warn-soft px-3 py-2 text-center text-sm text-status-warn">
            <span>⚠️</span>{{ flashWarning }}
        </p>

        <!-- STATE: nothing yet today → check in (or report absence) -->
        <template v-if="today.state === 'none'">
            <!-- Weekend rest day: no offer invites this worker → no check-in -->
            <div v-if="weekend.rest_day"
                class="rounded-lg border border-line bg-surface-raised p-6 text-center shadow-card">
                <p class="text-base font-semibold text-ink">{{ $t('worker.rest_day_title') }}</p>
                <p class="mt-1 text-sm text-ink-soft">{{ $t('worker.rest_day_body') }}</p>
            </div>

            <template v-else>
                <!-- A failed punch is shown here rather than silently swallowed -->
                <div v-if="checkInError" class="mb-3 rounded-lg bg-status-danger-soft px-4 py-3 text-center text-sm text-status-danger">
                    {{ checkInError }}
                </div>

                <!-- Weekend work offer for this worker (check-in unlocked) -->
                <div v-if="weekend.offer" class="mb-3 rounded-lg border border-accent/40 bg-accent-soft p-4 text-center shadow-card">
                    <p class="text-sm font-semibold text-accent">{{ $t('worker.weekend_offer_title') }}</p>
                    <p v-if="weekend.offer.project" class="mt-1 text-sm text-ink">
                        {{ $t('worker.detail_project') }}: {{ weekend.offer.project }}
                    </p>
                    <p class="mt-0.5 text-xs text-ink-soft">{{ weekendRateLabel }}</p>
                </div>

                <!-- Which site am I on? Weekday project picker; a weekend offer
                     locks the project (shown in the offer card above). -->
                <div v-if="!weekend.is_weekend && assignedProjects.length"
                    class="mb-3 rounded-lg border border-line bg-surface-raised p-4 shadow-card">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('worker.your_project') }}</p>

                    <p v-if="assignedProjects.length === 1" class="mt-1 text-base font-semibold text-ink">
                        {{ selectedProject?.name }}
                    </p>
                    <VSelect v-else v-model="selectedProjectId" class="mt-2">
                        <option :value="null">{{ $t('worker.choose_project') }}</option>
                        <option v-for="p in sortedProjects" :key="p.id" :value="p.id">
                            {{ p.name }}<template v-if="projectDistance(p) != null"> · {{ fmtDistance(projectDistance(p)) }}</template>
                        </option>
                    </VSelect>

                    <div class="mt-2 flex items-center gap-2">
                        <span v-if="selectedDistance != null" class="text-sm"
                            :class="distanceWarn ? 'text-status-warn' : 'text-status-ok'">
                            {{ $t('worker.distance_from_site') }}: {{ fmtDistance(selectedDistance) }}
                        </span>
                        <a v-if="mapsUrl(selectedProject)" :href="mapsUrl(selectedProject)" target="_blank" rel="noopener"
                            class="ml-auto text-sm font-medium text-accent hover:underline">{{ $t('worker.navigate') }} →</a>
                    </div>

                    <p v-if="distanceWarn" class="mt-2 rounded-md bg-status-warn-soft px-3 py-2 text-xs text-status-warn">
                        {{ $t('worker.distance_warning') }}
                    </p>
                </div>

                <!-- GPS auto-assign preview: the site the system will attach on
                     check-in (own company + deployed), when the worker hasn't
                     picked one and their fix is inside a project's radius.
                     Evidence, not a gate — the punch always succeeds. -->
                <div v-if="!weekend.is_weekend && autoDetectedProject"
                    class="mb-3 rounded-lg border border-status-ok/40 bg-status-ok-soft p-4 text-center shadow-card">
                    <p class="text-sm font-semibold text-status-ok">{{ $t('worker.at_project_confirm') }}</p>
                    <p class="mt-1 text-base font-semibold text-ink">{{ autoDetectedProject.name }} ✓</p>
                    <p class="mt-0.5 text-xs text-ink-soft">{{ $t('worker.at_project_hint') }}</p>
                </div>

                <div v-if="!cameraOpen" class="space-y-3">
                    <VButton class="w-full rounded-xl text-lg font-semibold" size="lg" @click="beginCheckIn">
                        {{ $t('worker.check_in') }}
                    </VButton>
                    <!-- Absence reporting only on normal weekdays -->
                    <VButton v-if="!weekend.is_weekend" variant="danger" size="lg" class="w-full rounded-xl" @click="absenceOpen = true">
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
            <!-- Green once a full day is in, amber (a warning) before that. -->
            <VButton :variant="dayComplete ? 'success' : 'warning'" class="w-full" size="lg" :loading="busy" @click="openCheckOut">
                {{ $t('worker.check_out') }}
            </VButton>
            <p class="mt-2 text-center text-xs" :class="dayComplete ? 'text-status-ok' : 'text-status-warn'">
                {{ dayComplete ? $t('worker.day_complete') : $t('worker.day_incomplete') }}
            </p>
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

        <!-- Mi Equipamiento — kit currently held (read-only, no money) -->
        <section v-if="equipment.length" class="mt-6">
            <h2 class="mb-3 text-sm font-semibold text-ink">{{ $t('worker_equipment.title') }}</h2>
            <div class="space-y-2">
                <div v-for="e in equipment" :key="e.id"
                    class="rounded-lg border border-line bg-surface-raised px-4 py-3 shadow-card">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-ink">
                            {{ e.item }}
                            <span v-if="e.serial" class="tabular-nums text-xs text-muted">· {{ e.serial }}</span>
                        </span>
                        <span v-if="e.overdue"
                            class="rounded-sm bg-status-danger-soft px-2 py-0.5 text-xs font-medium text-status-danger">
                            {{ $t('worker_equipment.overdue') }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-ink-soft">{{ $t('worker_equipment.issued') }} {{ e.issue_date }}</p>
                </div>
            </div>
        </section>

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


            <!-- Calendar (its own locale-aware legend lives inside the component) -->
            <div class="rounded-lg border border-line bg-surface-raised p-3 shadow-card">
                <MonthCalendar :month="month" />
            </div>
        </section>

        <!-- Branding -->
        <p class="mt-6 mb-2 text-center text-xs text-muted">{{ $t('worker.powered_by') }}</p>

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

                <!-- Proof-of-work photos: photo 1 required, 2 & 3 optional. -->
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('worker.work_attachment') }}</p>

                <!-- Photo 1 (required — a site photo or a document) -->
                <p class="mb-1 text-xs font-medium text-ink-soft">{{ $t('worker.photo_1_required') }}</p>
                <label v-if="!attachmentFile"
                    class="mb-1 flex cursor-pointer flex-col items-center gap-1 rounded-lg border-2 border-dashed border-line-strong bg-surface-sunken px-3 py-4 text-center">
                    <span class="text-2xl">📷</span>
                    <span class="text-sm font-medium text-ink">{{ $t('worker.work_attachment_prompt') }}</span>
                    <span class="text-xs text-muted">{{ $t('worker.work_attachment_hint') }}</span>
                    <input type="file" accept="image/*,.pdf,.doc,.docx" capture="environment" class="hidden" @change="onPhotoChange(1, $event)" />
                </label>
                <div v-else class="mb-1 flex items-center gap-3 rounded-lg border border-status-ok bg-status-ok-soft p-2">
                    <img v-if="photoPreviews[1]" :src="photoPreviews[1]" alt="" class="h-14 w-14 shrink-0 rounded object-cover" />
                    <span v-else class="text-2xl">📄</span>
                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink">{{ attachmentName }}</span>
                    <label class="shrink-0 cursor-pointer text-xs font-medium text-accent">
                        {{ $t('worker.retake') }}
                        <input type="file" accept="image/*,.pdf,.doc,.docx" capture="environment" class="hidden" @change="onPhotoChange(1, $event)" />
                    </label>
                    <button type="button" class="shrink-0 text-xs font-medium text-status-danger" @click="removePhoto(1)">{{ $t('worker.delete_photo') }}</button>
                </div>

                <!-- Photo 2 (optional — appears after photo 1) -->
                <template v-if="attachmentFile">
                    <p class="mb-1 mt-3 text-xs font-medium text-ink-soft">{{ $t('worker.photo_2_optional') }}</p>
                    <label v-if="!photo2File"
                        class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border-2 border-dashed border-line-strong bg-surface-sunken px-3 py-2.5 text-sm text-ink-soft">
                        <span>＋</span><span>{{ $t('worker.add_photo') }}</span>
                        <input type="file" accept="image/*" capture="environment" class="hidden" @change="onPhotoChange(2, $event)" />
                    </label>
                    <div v-else class="flex items-center gap-3 rounded-lg border border-status-ok bg-status-ok-soft p-2">
                        <img v-if="photoPreviews[2]" :src="photoPreviews[2]" alt="" class="h-14 w-14 shrink-0 rounded object-cover" />
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink">{{ photo2File.name }}</span>
                        <label class="shrink-0 cursor-pointer text-xs font-medium text-accent">
                            {{ $t('worker.retake') }}
                            <input type="file" accept="image/*" capture="environment" class="hidden" @change="onPhotoChange(2, $event)" />
                        </label>
                        <button type="button" class="shrink-0 text-xs font-medium text-status-danger" @click="removePhoto(2)">{{ $t('worker.delete_photo') }}</button>
                    </div>
                </template>

                <!-- Photo 3 (optional — appears after photo 2) -->
                <template v-if="photo2File">
                    <p class="mb-1 mt-3 text-xs font-medium text-ink-soft">{{ $t('worker.photo_3_optional') }}</p>
                    <label v-if="!photo3File"
                        class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border-2 border-dashed border-line-strong bg-surface-sunken px-3 py-2.5 text-sm text-ink-soft">
                        <span>＋</span><span>{{ $t('worker.add_photo') }}</span>
                        <input type="file" accept="image/*" capture="environment" class="hidden" @change="onPhotoChange(3, $event)" />
                    </label>
                    <div v-else class="flex items-center gap-3 rounded-lg border border-status-ok bg-status-ok-soft p-2">
                        <img v-if="photoPreviews[3]" :src="photoPreviews[3]" alt="" class="h-14 w-14 shrink-0 rounded object-cover" />
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink">{{ photo3File.name }}</span>
                        <label class="shrink-0 cursor-pointer text-xs font-medium text-accent">
                            {{ $t('worker.retake') }}
                            <input type="file" accept="image/*" capture="environment" class="hidden" @change="onPhotoChange(3, $event)" />
                        </label>
                        <button type="button" class="shrink-0 text-xs font-medium text-status-danger" @click="removePhoto(3)">{{ $t('worker.delete_photo') }}</button>
                    </div>
                </template>

                <p class="mb-4 mt-2 text-xs" :class="attachmentFile ? 'text-status-ok' : 'text-status-warn'">
                    {{ attachmentFile ? $t('worker.work_attachment_added') : $t('worker.work_attachment_required') }}
                </p>

                <!-- Note section (optional) -->
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('worker.voice_note') }}</p>
                <div class="mb-3">
                    <VoiceRecorder v-model="voiceNote" :max-seconds="120" />
                </div>
                <VTextarea v-model="noteTextForm.text_note" :rows="2" :placeholder="$t('worker.note_text_placeholder')" class="mb-4" />

                <div class="flex gap-2">
                    <VButton variant="ghost" class="flex-1" type="button" @click="checkOutOpen = false">
                        {{ $t('common.cancel') }}
                    </VButton>
                    <VButton :variant="dayComplete ? 'success' : 'warning'" class="flex-1" :loading="busy" :disabled="!attachmentFile" @click="submitCheckOut">
                        {{ $t('worker.confirm_check_out') }}
                    </VButton>
                </div>
            </div>
        </div>
    </WorkerLayout>
</template>
