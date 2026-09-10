<script setup>
/**
 * Screen 13 — Call Panel.
 * Left column: employee list with follow-up triage.
 * Right column: log form (auto-fills today, voice recording, file attachment)
 * + full call history with download and inline rename.
 */
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VStatusDot from '@/Components/ui/VStatusDot.vue';
import VDropdown from '@/Components/ui/VDropdown.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const page = usePage();

const props = defineProps({
    employees: { type: Array, required: true },
    filters: { type: Object, required: true },
    selected: { type: Object, default: null },
    overview: { type: Object, required: true },
    callOutcomes: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

// ONE date filter drives the whole overview: a month (default), an arbitrary
// custom range, or all-time. The left "who to call" list stays absolute.
const state = reactive({
    search: props.filters.search ?? '',
    tab: props.filters.tab ?? 'all',
    mode: props.filters.range ?? 'month', // month | custom | all
    month: props.filters.month ?? currentMonth(),
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const tabs = [
    { key: 'all', labelKey: 'calls.tab_all' },
    { key: 'pending', labelKey: 'calls.tab_pending' },
    { key: 'not_contacted', labelKey: 'calls.tab_not_contacted' },
];

// Which of the four category lists is expanded below the cards.
const activeCategory = ref('calls_made');

// The four month-overview categories (icon + semantic tone + live count).
const categories = computed(() => [
    { key: 'calls_made', icon: 'calls', tone: 'accent', count: props.overview.calls_made.count },
    { key: 'connected', icon: 'check', tone: 'ok', count: props.overview.connected.count },
    { key: 'not_connected', icon: 'x', tone: 'danger', count: props.overview.not_connected.count },
    { key: 'follow_ups', icon: 'bell', tone: 'warn', count: props.overview.follow_ups.count },
]);
const toneRing = { accent: 'ring-accent', ok: 'ring-status-ok', danger: 'ring-status-danger', warn: 'ring-status-warn' };
const toneText = { accent: 'text-accent', ok: 'text-status-ok', danger: 'text-status-danger', warn: 'text-status-warn' };
const toneSoftBg = { accent: 'bg-accent-soft', ok: 'bg-status-ok-soft', danger: 'bg-status-danger-soft', warn: 'bg-status-warn-soft' };

function currentMonth() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}
// A human month label ("September 2026") for the navigator.
function monthLabel(ym) {
    if (! ym) return '';
    const [y, m] = ym.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}
function shiftMonth(ym, delta) {
    const [y, m] = ym.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function setMode(mode) {
    state.mode = mode;
    if (mode === 'month' && ! state.month) state.month = currentMonth();
    applyOverview();
}
function goMonth(delta) {
    state.mode = 'month';
    state.month = shiftMonth(state.month || currentMonth(), delta);
    applyOverview();
}
function onMonthPick() { state.mode = 'month'; applyOverview(); }
function onCustomDate() {
    // Only apply once both ends are set (a half range would filter oddly).
    if (state.from && state.to) applyOverview();
}

// The overview date params for the current mode.
function rangeParams() {
    if (state.mode === 'all') return { range: 'all' };
    if (state.mode === 'custom') return { from: state.from || undefined, to: state.to || undefined };
    return { month: state.month || currentMonth() };
}

function buildParams(extra = {}) {
    return {
        search: state.search || undefined,
        tab: state.tab || undefined,
        employee: props.selected?.id,
        ...rangeParams(),
        ...extra,
    };
}

// A range change only touches the overview + filters (the left list is absolute).
function applyOverview() {
    router.get('/calls', buildParams(), { preserveScroll: true, preserveState: true, only: ['overview', 'filters'] });
}

function apply(extra = {}) {
    router.get('/calls', buildParams(extra), { preserveScroll: true, preserveState: true });
}

// Debounce the search box: reload 350ms after the last keystroke, not on every
// character (tabs/filters stay immediate).
let searchTimer = null;
function searchApply() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => apply(), 350);
}
function select(employee) {
    router.get('/calls', buildParams({ employee: employee.id }), { preserveScroll: true, preserveState: true });
}

/* ── Helpers ─────────────────────────────────────────────── */

function localNow() {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/* ── Log form ────────────────────────────────────────────── */

const form = useForm({
    employee_id: null,
    called_at: localNow(),
    call_outcome: 'connected',
    remarks: '',
    follow_up_date: null,
    voice_note: null,
    voice_note_label: '',
    attachment: null,
    attachment_label: '',
});

// value → localized label + a semantic status for the outcome badge.
const outcomeLabel = (v) => {
    const o = props.callOutcomes.find((c) => c.value === v);
    if (! o) return null;
    return (page.props.locale?.primary ?? 'es') === 'en' ? o.label_en : o.label_es;
};
const outcomeStatus = (v) => (v === 'connected' ? 'ok' : v === 'no_answer' ? 'danger' : 'neutral');

watch(() => props.selected?.id, (id) => {
    form.employee_id = id ?? null;
}, { immediate: true });

function resetForm() {
    form.remarks = '';
    form.call_outcome = 'connected';
    form.follow_up_date = null;
    form.voice_note = null;
    form.voice_note_label = '';
    form.attachment = null;
    form.attachment_label = '';
    form.called_at = localNow();
    form.employee_id = props.selected?.id ?? null;
    // reset recording
    clearRecording();
    attachmentFile.value = null;
}

function submit() {
    form.post('/calls', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => resetForm(),
    });
}

/* ── Voice recording (MediaRecorder) ────────────────────── */
/*
 * Two modes:
 *  - Mic only (default): records the admin's microphone.
 *  - Both sides: also captures SYSTEM audio (via getDisplayMedia "share
 *    system audio") — the other party coming out of WhatsApp Desktop / Web on
 *    the same PC — and MIXES it with the mic through the Web Audio API into one
 *    note. Windows + Chrome/Edge only; if the admin declines the system-audio
 *    share we fall back to mic-only and SAY SO (never silently record one side
 *    while implying both).
 */
const recordingState = ref('idle'); // idle | requesting | recording | done | denied
const recordBothSides = ref(false);
const captureNotice = ref(''); // '' | 'both' | 'mic_only'
const audioUrl = ref(null);
const recordingDuration = ref(0);
let mediaRecorder = null;
let audioChunks = [];
let durationTimer = null;
let micStream = null;
let systemStream = null;
let audioContext = null;

function stopStreams() {
    micStream?.getTracks().forEach((t) => t.stop());
    systemStream?.getTracks().forEach((t) => t.stop());
    micStream = null;
    systemStream = null;
    if (audioContext) {
        audioContext.close().catch(() => {});
        audioContext = null;
    }
}

async function startRecording() {
    recordingState.value = 'requesting';
    captureNotice.value = '';
    try {
        micStream = await navigator.mediaDevices.getUserMedia({ audio: true });

        // Decide which stream MediaRecorder listens to.
        let recordStream = micStream;

        if (recordBothSides.value) {
            let systemAudioTrack = null;
            try {
                // video:true is required to surface the picker; we keep only the
                // audio. The admin must tick "Also share system/tab audio".
                systemStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true });
                systemStream.getVideoTracks().forEach((t) => t.stop()); // drop video, keep audio
                systemAudioTrack = systemStream.getAudioTracks()[0] ?? null;
            } catch { /* user cancelled the share — handled below */ }

            if (systemAudioTrack) {
                // Mix mic + system audio into one output stream.
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const dest = audioContext.createMediaStreamDestination();
                audioContext.createMediaStreamSource(micStream).connect(dest);
                audioContext.createMediaStreamSource(new MediaStream([systemAudioTrack])).connect(dest);
                recordStream = dest.stream;
                captureNotice.value = 'both';
                // If the admin clicks the browser's "Stop sharing" bar, finalise.
                systemAudioTrack.addEventListener('ended', () => {
                    if (recordingState.value === 'recording') stopRecording();
                });
            } else {
                // No system audio was shared — record mic only, and say so.
                systemStream?.getTracks().forEach((t) => t.stop());
                systemStream = null;
                captureNotice.value = 'mic_only';
            }
        }

        const mimeType = ['audio/webm', 'audio/ogg', 'audio/mp4'].find(
            (t) => MediaRecorder.isTypeSupported(t),
        ) ?? '';

        mediaRecorder = new MediaRecorder(recordStream, mimeType ? { mimeType } : {});
        audioChunks = [];

        mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) audioChunks.push(e.data);
        };

        mediaRecorder.onstop = () => {
            const usedType = mediaRecorder.mimeType || 'audio/webm';
            const blob = new Blob(audioChunks, { type: usedType });
            const ext = usedType.includes('ogg') ? 'ogg' : usedType.includes('mp4') ? 'mp4' : 'webm';
            const name = `voice-note-${Date.now()}.${ext}`;
            const file = new File([blob], name, { type: usedType });
            form.voice_note = file;
            if (!form.voice_note_label) form.voice_note_label = `Voice Note ${new Date().toLocaleTimeString()}`;
            audioUrl.value = URL.createObjectURL(blob);
            recordingState.value = 'done';
            stopStreams();
        };

        mediaRecorder.start();
        recordingState.value = 'recording';
        recordingDuration.value = 0;
        durationTimer = setInterval(() => recordingDuration.value++, 1000);
    } catch {
        stopStreams();
        recordingState.value = 'denied';
    }
}

function stopRecording() {
    clearInterval(durationTimer);
    mediaRecorder?.stop();
}

function clearRecording() {
    if (audioUrl.value) URL.revokeObjectURL(audioUrl.value);
    audioUrl.value = null;
    form.voice_note = null;
    form.voice_note_label = '';
    recordingState.value = 'idle';
    recordingDuration.value = 0;
    captureNotice.value = '';
    clearInterval(durationTimer);
    stopStreams();
    mediaRecorder = null;
}

function fmtDuration(s) {
    return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
}

// If the admin navigates away mid-recording, release the mic / system-audio
// capture, the AudioContext and every timer — otherwise the browser keeps the
// microphone live after the page is gone.
onUnmounted(() => {
    clearInterval(durationTimer);
    clearTimeout(searchTimer);
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        try { mediaRecorder.stop(); } catch { /* already stopped */ }
    }
    stopStreams();
    if (audioUrl.value) URL.revokeObjectURL(audioUrl.value);
});

/* ── Contact actions ─────────────────────────────────────── */

const numberCopied = ref(false);

function digitsOnly(num) {
    return (num ?? '').replace(/\D/g, '');
}

function copyNumber(num) {
    navigator.clipboard.writeText(num ?? '').then(() => {
        numberCopied.value = true;
        setTimeout(() => (numberCopied.value = false), 2000);
    });
}

/* ── File attachment ─────────────────────────────────────── */

const attachmentFile = ref(null);
const attachmentInput = ref(null);

function pickAttachment(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    attachmentFile.value = file;
    form.attachment = file;
    if (!form.attachment_label) form.attachment_label = file.name;
}

function clearAttachment() {
    attachmentFile.value = null;
    form.attachment = null;
    form.attachment_label = '';
    if (attachmentInput.value) attachmentInput.value.value = '';
}

/* ── Rename (inline edit in history) ───────────────────── */

const renaming = ref(null); // { callId, type, label }

function startRename(callId, type, currentLabel) {
    renaming.value = { callId, type, label: currentLabel ?? '' };
}

function saveRename() {
    if (!renaming.value) return;
    const { callId, type, label } = renaming.value;
    router.patch(`/calls/${callId}/label`, { type, label }, {
        preserveScroll: true,
        onSuccess: () => (renaming.value = null),
    });
}

/* ── Misc ────────────────────────────────────────────────── */

const dotStatus = { red: 'danger', amber: 'warn', green: 'ok' };
</script>

<template>
    <Head :title="$t('calls.title')" />
    <AppLayout>
        <VPageHeader k="calls.title" />

        <!-- ONE overview filter: month navigator / custom range / all-time. -->
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-line bg-surface-raised px-4 py-3 shadow-card">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $t('calls.overview') }}</span>

            <div class="inline-flex rounded-lg border border-line bg-surface-sunken p-0.5 text-xs font-medium">
                <button v-for="m in ['month', 'custom', 'all']" :key="m" type="button"
                    class="rounded-md px-3 py-1 transition"
                    :class="state.mode === m ? 'bg-surface-raised text-ink shadow-card' : 'text-ink-soft hover:text-ink'"
                    @click="setMode(m)">{{ $t(`calls.mode_${m}`) }}</button>
            </div>

            <!-- Month navigator -->
            <div v-if="state.mode === 'month'" class="inline-flex items-center gap-1">
                <button type="button" class="rounded-md border border-line p-1.5 text-ink-soft hover:bg-surface-hover"
                    :title="$t('calls.prev_month')" @click="goMonth(-1)">
                    <AppIcon name="chevron-right" class="h-4 w-4 rotate-180" />
                </button>
                <input v-model="state.month" type="month"
                    class="tabular-nums rounded-md border border-line bg-surface-raised px-3 py-1.5 text-sm text-ink"
                    @change="onMonthPick" />
                <button type="button" class="rounded-md border border-line p-1.5 text-ink-soft hover:bg-surface-hover"
                    :title="$t('calls.next_month')" @click="goMonth(1)">
                    <AppIcon name="chevron-right" class="h-4 w-4" />
                </button>
            </div>

            <!-- Custom range -->
            <div v-else-if="state.mode === 'custom'" class="inline-flex items-center gap-2">
                <VDateInput v-model="state.from" class="w-40" @update:model-value="onCustomDate()" />
                <span class="text-xs text-muted">–</span>
                <VDateInput v-model="state.to" class="w-40" @update:model-value="onCustomDate()" />
            </div>

            <span class="ms-auto text-sm font-semibold text-ink">{{ overview.period_label }}</span>
        </div>

        <!-- Four clearly-separated category cards -->
        <div class="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button v-for="cat in categories" :key="cat.key" type="button"
                class="rounded-xl bg-surface-raised p-4 text-start shadow-card transition"
                :class="activeCategory === cat.key ? `ring-2 ${toneRing[cat.tone]}` : 'border border-line hover:shadow-raised'"
                @click="activeCategory = cat.key">
                <div class="flex items-center justify-between">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg" :class="toneSoftBg[cat.tone]">
                        <AppIcon :name="cat.icon" class="h-4 w-4" :class="toneText[cat.tone]" />
                    </span>
                    <span class="tabular-nums text-2xl font-semibold text-ink">{{ cat.count }}</span>
                </div>
                <p class="mt-2 text-sm font-medium text-ink">{{ $t(`calls.cat_${cat.key}`) }}</p>
                <p class="text-xs text-muted">{{ $t(`calls.cat_${cat.key}_hint`) }}</p>
            </button>
        </div>

        <!-- The selected category's people/calls, clearly separated (not mixed) -->
        <div class="mb-4 rounded-lg border border-line bg-surface-raised p-4 shadow-card">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-ink">{{ $t(`calls.cat_${activeCategory}`) }}</h3>
                <span class="tabular-nums text-xs text-muted">{{ overview[activeCategory].count }} · {{ overview.period_label }}</span>
            </div>

            <!-- Calls made: the calls themselves -->
            <ul v-if="activeCategory === 'calls_made'" class="max-h-96 divide-y divide-line overflow-y-auto">
                <li v-for="c in overview.calls_made.calls" :key="c.id" class="flex flex-wrap items-center gap-2 py-2">
                    <button type="button" class="min-w-0 flex-1 text-start" @click="c.employee_id && select({ id: c.employee_id })">
                        <span class="block truncate text-sm font-medium text-ink">{{ c.name ?? '—' }}</span>
                        <span class="block truncate text-xs text-muted">{{ c.remarks || '—' }}</span>
                    </button>
                    <VBadge :status="outcomeStatus(c.outcome)">{{ outcomeLabel(c.outcome) ?? '—' }}</VBadge>
                    <span class="tabular-nums w-40 shrink-0 text-end text-xs text-muted">{{ c.called_at }}</span>
                </li>
                <li v-if="overview.calls_made.calls.length === 0" class="py-6 text-center text-sm text-muted">{{ $t('calls.cat_empty') }}</li>
            </ul>

            <!-- Connected / Not connected: distinct people -->
            <ul v-else-if="activeCategory === 'connected' || activeCategory === 'not_connected'"
                class="max-h-96 divide-y divide-line overflow-y-auto">
                <li v-for="p in overview[activeCategory].people" :key="p.id" class="flex items-center gap-3 py-2">
                    <VAvatar :name="p.name" size="sm" />
                    <button type="button" class="min-w-0 flex-1 text-start" @click="select({ id: p.id })">
                        <span class="block truncate text-sm font-medium text-ink">{{ p.name }}</span>
                        <span class="block truncate text-xs text-muted">{{ p.designation || '—' }}</span>
                    </button>
                    <span class="tabular-nums shrink-0 text-xs text-muted">{{ $t('calls.calls_n', { n: p.calls }) }}</span>
                </li>
                <li v-if="overview[activeCategory].people.length === 0" class="py-6 text-center text-sm text-muted">{{ $t('calls.cat_empty') }}</li>
            </ul>

            <!-- Follow-ups: people needing a callback in the period -->
            <ul v-else class="max-h-96 divide-y divide-line overflow-y-auto">
                <li v-for="p in overview.follow_ups.people" :key="p.id" class="flex items-center gap-3 py-2">
                    <VStatusDot :status="dotStatus[p.indicator]" :pulse="p.indicator === 'red'" />
                    <button type="button" class="min-w-0 flex-1 text-start" @click="select({ id: p.id })">
                        <span class="block truncate text-sm font-medium text-ink">{{ p.name }}</span>
                        <span class="block truncate text-xs text-muted">{{ p.designation || '—' }}</span>
                    </button>
                    <span class="tabular-nums shrink-0 text-xs text-status-warn">{{ p.follow_up_date }}</span>
                </li>
                <li v-if="overview.follow_ups.people.length === 0" class="py-6 text-center text-sm text-muted">{{ $t('calls.cat_empty') }}</li>
            </ul>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            <!-- Left: who to call -->
            <div class="flex flex-col gap-3">
                <VSearchInput v-model="state.search" :placeholder="$t('calls.search')" @update:model-value="searchApply()" />
                <VTabs v-model="state.tab" :tabs="tabs" @update:model-value="apply({ tab: $event })" />

                <div class="flex max-h-[32rem] flex-col overflow-y-auto rounded-lg border border-line">
                    <button v-for="e in employees" :key="e.id" type="button"
                        class="flex items-center gap-3 border-b border-line px-3 py-2.5 text-start transition-colors duration-150 last:border-b-0"
                        :class="selected?.id === e.id ? 'bg-accent-soft' : 'hover:bg-surface-hover'"
                        @click="select(e)">
                        <VAvatar :name="e.name" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ e.name }}</span>
                            <span class="block truncate text-xs text-muted">
                                <template v-if="e.last_contacted">{{ $t('calls.last_contacted') }}: {{ e.last_contacted }}</template>
                                <template v-else>{{ $t('calls.never_contacted') }}</template>
                            </span>
                        </span>
                        <VStatusDot :status="dotStatus[e.indicator]" :pulse="e.indicator === 'red'" />
                    </button>
                    <p v-if="employees.length === 0" class="px-3 py-6 text-center text-sm text-muted">
                        <Bilingual k="calls.no_calls" inline />
                    </p>
                </div>
            </div>

            <!-- Right: the selected worker -->
            <div v-if="selected" class="flex flex-col gap-4">
                <!-- Worker card -->
                <VCard>
                    <div class="flex flex-wrap items-center gap-3">
                        <VAvatar :name="selected.name" size="lg" />
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-lg font-semibold">{{ selected.name }}</h2>
                            <p class="truncate text-sm text-muted">
                                {{ [selected.company, selected.designation].filter(Boolean).join(' · ') || '—' }}
                            </p>
                        </div>
                        <!-- Contact dropdown: WhatsApp · call · copy -->
                        <VDropdown v-if="selected.mobile" align="end" width="w-52" placement="bottom">
                            <template #trigger="{ toggle, open: dpOpen }">
                                <button type="button"
                                    class="tabular-nums inline-flex shrink-0 items-center gap-2 rounded-lg bg-accent px-3.5 py-2 text-sm font-medium text-on-accent shadow-card transition-colors duration-150 hover:bg-accent-hover"
                                    :class="dpOpen ? 'bg-accent-hover' : ''"
                                    @click="toggle">
                                    <AppIcon name="calls" class="h-4 w-4" />
                                    <span>{{ selected.mobile }}</span>
                                    <AppIcon name="chevron-down" class="h-3 w-3 transition-transform" :class="dpOpen ? 'rotate-180' : ''" />
                                </button>
                            </template>
                            <template #default="{ close }">
                                <!-- WhatsApp -->
                                <a :href="`https://wa.me/${digitsOnly(selected.mobile)}`"
                                    target="_blank" rel="noopener"
                                    class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm text-ink transition hover:bg-surface-hover"
                                    @click="close">
                                    <AppIcon name="whatsapp" class="h-4 w-4 text-[#25D366]" />
                                    <Bilingual k="calls.whatsapp" inline />
                                </a>
                                <!-- Direct call -->
                                <a :href="`tel:${selected.mobile}`"
                                    class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm text-ink transition hover:bg-surface-hover"
                                    @click="close">
                                    <AppIcon name="calls" class="h-4 w-4 text-accent" />
                                    <Bilingual k="calls.call" inline />
                                </a>
                                <!-- Copy number -->
                                <button type="button"
                                    class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm text-ink transition hover:bg-surface-hover"
                                    @click="copyNumber(selected.mobile); close()">
                                    <AppIcon name="copy" class="h-4 w-4 text-ink-soft" />
                                    <Bilingual :k="numberCopied ? 'calls.number_copied' : 'calls.copy_number'" inline />
                                </button>
                            </template>
                        </VDropdown>
                    </div>
                </VCard>

                <!-- Log call form -->
                <VCard v-if="can.create">
                    <form class="space-y-4" @submit.prevent="submit">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <!-- Auto-filled with current date-time -->
                            <FormField k="calls.called_at" :error="form.errors.called_at">
                                <VDateInput v-model="form.called_at" type="datetime-local" />
                            </FormField>
                            <FormField k="calls.follow_up_date" :error="form.errors.follow_up_date">
                                <VDateInput v-model="form.follow_up_date" />
                            </FormField>
                        </div>

                        <!-- Outcome: did the worker pick up? Drives the Connected /
                             Not connected split on the month overview. -->
                        <FormField k="calls.outcome" :error="form.errors.call_outcome">
                            <div class="inline-flex rounded-lg border border-line bg-surface-sunken p-0.5 text-sm font-medium">
                                <button v-for="o in callOutcomes" :key="o.value" type="button"
                                    class="rounded-md px-4 py-1.5 transition"
                                    :class="form.call_outcome === o.value
                                        ? (o.value === 'connected' ? 'bg-status-ok-soft text-status-ok' : 'bg-status-danger-soft text-status-danger')
                                        : 'text-ink-soft hover:text-ink'"
                                    @click="form.call_outcome = o.value">
                                    {{ (page.props.locale?.primary ?? 'es') === 'en' ? o.label_en : o.label_es }}
                                </button>
                            </div>
                        </FormField>

                        <FormField k="calls.remarks" :error="form.errors.remarks" required>
                            <VTextarea v-model="form.remarks" :rows="3" :placeholder="$t('calls.remarks_placeholder')" />
                        </FormField>

                        <!-- Voice note section -->
                        <div class="rounded-lg border border-line bg-surface-sunken p-3">
                            <p class="mb-2 text-sm font-medium text-ink">
                                <Bilingual k="calls.voice_note_optional" inline />
                            </p>

                            <!-- Idle: record button + both-sides option -->
                            <div v-if="recordingState === 'idle'" class="space-y-2">
                                <button type="button"
                                    class="inline-flex items-center gap-2 rounded-full border border-line-strong bg-surface-raised px-4 py-2 text-sm font-medium text-ink hover:bg-surface-hover"
                                    @click="startRecording">
                                    <AppIcon name="mic" class="h-4 w-4 text-accent" />
                                    <Bilingual k="calls.record" inline />
                                </button>

                                <label class="flex cursor-pointer items-start gap-2 text-xs text-ink-soft">
                                    <input v-model="recordBothSides" type="checkbox"
                                        class="mt-0.5 h-3.5 w-3.5 shrink-0 accent-[var(--color-accent)]" />
                                    <span>
                                        <span class="font-medium text-ink"><Bilingual k="calls.record_both" inline /></span>
                                        <span class="mt-0.5 block text-muted">{{ $t('calls.record_both_hint') }}</span>
                                    </span>
                                </label>
                            </div>

                            <!-- Requesting mic / system-audio permission -->
                            <p v-else-if="recordingState === 'requesting'" class="text-sm text-muted">
                                <Bilingual k="calls.record" inline />…
                            </p>

                            <!-- Recording in progress -->
                            <div v-else-if="recordingState === 'recording'" class="space-y-2">
                                <div class="flex items-center gap-3">
                                    <span class="flex items-center gap-1.5">
                                        <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-status-danger" />
                                        <span class="tabular-nums text-sm font-semibold text-status-danger">{{ fmtDuration(recordingDuration) }}</span>
                                    </span>
                                    <button type="button"
                                        class="inline-flex items-center gap-2 rounded-full border border-status-danger bg-status-danger-soft px-4 py-2 text-sm font-medium text-status-danger hover:bg-status-danger hover:text-white"
                                        @click="stopRecording">
                                        <AppIcon name="stop" class="h-4 w-4" />
                                        <Bilingual k="calls.stop_recording" inline />
                                    </button>
                                </div>
                                <p v-if="captureNotice === 'both'" class="text-xs font-medium text-status-ok">{{ $t('calls.both_sides_active') }}</p>
                                <p v-else-if="captureNotice === 'mic_only'" class="text-xs font-medium text-status-warn">{{ $t('calls.mic_only_fallback') }}</p>
                                <p class="text-xs text-muted">{{ $t('calls.record_notice') }}</p>
                            </div>

                            <!-- Recording done — playback + label -->
                            <div v-else-if="recordingState === 'done'" class="space-y-2">
                                <p v-if="captureNotice === 'both'" class="text-xs font-medium text-status-ok">✓ {{ $t('calls.both_sides_active') }}</p>
                                <audio :src="audioUrl" controls preload="metadata" class="w-full" />
                                <div class="flex items-center gap-2">
                                    <VInput v-model="form.voice_note_label" class="flex-1" :placeholder="$t('calls.label_placeholder')" />
                                    <button type="button"
                                        class="shrink-0 rounded-md p-2 text-status-danger hover:bg-status-danger-soft"
                                        :title="$t('calls.clear_recording')"
                                        @click="clearRecording">
                                        <AppIcon name="trash" class="h-4 w-4" />
                                    </button>
                                </div>
                                <p v-if="form.errors.voice_note" class="text-xs text-status-danger">{{ form.errors.voice_note }}</p>
                            </div>

                            <!-- Mic denied -->
                            <p v-else-if="recordingState === 'denied'" class="text-sm text-status-danger">
                                <Bilingual k="calls.mic_denied" inline />
                            </p>
                        </div>

                        <!-- File attachment -->
                        <div class="rounded-lg border border-line bg-surface-sunken p-3">
                            <p class="mb-2 text-sm font-medium text-ink">
                                <Bilingual k="calls.attachment_optional" inline />
                                <span class="ms-1 text-xs font-normal text-muted">mp3, mp4, imagen, pdf — máx 100 MB</span>
                            </p>

                            <div v-if="!attachmentFile">
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border-2 border-dashed border-line p-4 text-sm text-muted hover:border-accent hover:text-accent">
                                    <AppIcon name="upload" class="h-5 w-5" />
                                    <span><Bilingual k="common.upload_browse" inline /></span>
                                    <input ref="attachmentInput" type="file"
                                        accept="audio/mpeg,audio/mp4,audio/ogg,audio/webm,video/mp4,image/jpeg,image/png,image/webp,application/pdf"
                                        class="hidden"
                                        @change="pickAttachment" />
                                </label>
                            </div>

                            <div v-else class="space-y-2">
                                <div class="flex items-center gap-2 rounded-md bg-surface-raised px-3 py-2 text-sm">
                                    <AppIcon name="file" class="h-4 w-4 shrink-0 text-accent" />
                                    <span class="min-w-0 flex-1 truncate text-ink">{{ attachmentFile.name }}</span>
                                    <button type="button"
                                        class="shrink-0 rounded-md p-1 text-status-danger hover:bg-status-danger-soft"
                                        @click="clearAttachment">
                                        <AppIcon name="trash" class="h-4 w-4" />
                                    </button>
                                </div>
                                <VInput v-model="form.attachment_label" :placeholder="$t('calls.label_placeholder')" />
                                <p v-if="form.errors.attachment" class="text-xs text-status-danger">{{ form.errors.attachment }}</p>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <VButton type="submit" :loading="form.processing" icon="plus">
                                <Bilingual k="calls.log_call" inline />
                            </VButton>
                        </div>
                    </form>
                </VCard>

                <!-- Call history (full history — the one date filter is the overview one) -->
                <VCard>
                    <h3 class="mb-3 text-sm font-semibold"><Bilingual k="calls.call_history" inline /></h3>
                    <ul v-if="selected.calls.length" class="flex flex-col gap-4">
                        <li v-for="c in selected.calls" :key="c.id"
                            class="rounded-lg border border-line bg-surface-raised p-3 last:mb-0">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <span class="tabular-nums text-sm font-semibold">{{ c.called_at }}</span>
                                    <VBadge v-if="c.outcome" :status="outcomeStatus(c.outcome)">{{ outcomeLabel(c.outcome) }}</VBadge>
                                </span>
                                <span class="text-xs text-muted">{{ c.called_by ?? '—' }}</span>
                            </div>
                            <p class="mt-1.5 text-sm text-ink-soft">{{ c.remarks }}</p>
                            <p v-if="c.follow_up_date" class="tabular-nums mt-1 text-xs text-status-warn">
                                <Bilingual k="calls.follow_up_date" inline />: {{ c.follow_up_date }}
                            </p>

                            <!-- Voice note in this call entry -->
                            <div v-if="c.has_voice_note" class="mt-2 flex items-center gap-2">
                                <AppIcon name="mic" class="h-4 w-4 shrink-0 text-ink-soft" />

                                <!-- Rename mode -->
                                <template v-if="renaming?.callId === c.id && renaming?.type === 'voice'">
                                    <input v-model="renaming.label"
                                        class="flex-1 rounded border border-line-strong bg-surface px-2 py-1 text-xs focus:border-accent focus:outline-none"
                                        @keydown.enter.prevent="saveRename"
                                        @keydown.escape.prevent="renaming = null" />
                                    <button type="button" class="text-xs font-medium text-accent hover:underline" @click="saveRename">
                                        <Bilingual k="common.save" inline />
                                    </button>
                                    <button type="button" class="text-xs text-muted hover:text-ink" @click="renaming = null">✕</button>
                                </template>

                                <!-- Display mode -->
                                <template v-else>
                                    <span class="min-w-0 flex-1 truncate text-xs font-medium text-ink">
                                        {{ c.voice_note_label ?? $t('calls.voice_note') }}
                                    </span>
                                    <a :href="`/calls/${c.id}/download?type=voice`"
                                        class="shrink-0 text-xs text-accent hover:underline"
                                        download>
                                        <Bilingual k="calls.download" inline />
                                    </a>
                                    <button v-if="can.edit" type="button"
                                        class="shrink-0 text-muted hover:text-ink"
                                        :title="$t('calls.rename')"
                                        @click="startRename(c.id, 'voice', c.voice_note_label)">
                                        <AppIcon name="edit" class="h-3.5 w-3.5" />
                                    </button>
                                </template>
                            </div>

                            <!-- Inline player: stream the note from the gated route -->
                            <div v-if="c.has_voice_note && !(renaming?.callId === c.id && renaming?.type === 'voice')" class="mt-1.5 ps-6">
                                <audio :src="`/calls/${c.id}/download?type=voice`" controls preload="none" class="h-9 w-full"></audio>
                            </div>

                            <!-- File attachment in this call entry -->
                            <div v-if="c.has_attachment" class="mt-2 flex items-center gap-2">
                                <AppIcon name="file" class="h-4 w-4 shrink-0 text-ink-soft" />

                                <!-- Rename mode -->
                                <template v-if="renaming?.callId === c.id && renaming?.type === 'attachment'">
                                    <input v-model="renaming.label"
                                        class="flex-1 rounded border border-line-strong bg-surface px-2 py-1 text-xs focus:border-accent focus:outline-none"
                                        @keydown.enter.prevent="saveRename"
                                        @keydown.escape.prevent="renaming = null" />
                                    <button type="button" class="text-xs font-medium text-accent hover:underline" @click="saveRename">
                                        <Bilingual k="common.save" inline />
                                    </button>
                                    <button type="button" class="text-xs text-muted hover:text-ink" @click="renaming = null">✕</button>
                                </template>

                                <!-- Display mode -->
                                <template v-else>
                                    <span class="min-w-0 flex-1 truncate text-xs font-medium text-ink">
                                        {{ c.attachment_label ?? c.attachment_original_name ?? $t('calls.attachment') }}
                                    </span>
                                    <a :href="`/calls/${c.id}/download?type=attachment`"
                                        class="shrink-0 text-xs text-accent hover:underline"
                                        download>
                                        <Bilingual k="calls.download" inline />
                                    </a>
                                    <button v-if="can.edit" type="button"
                                        class="shrink-0 text-muted hover:text-ink"
                                        :title="$t('calls.rename')"
                                        @click="startRename(c.id, 'attachment', c.attachment_label)">
                                        <AppIcon name="edit" class="h-3.5 w-3.5" />
                                    </button>
                                </template>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="py-4 text-center text-sm text-muted">
                        <Bilingual k="calls.no_calls" inline />
                    </p>
                </VCard>
            </div>

            <VEmptyState v-else icon="calls" title-key="calls.select_employee"
                message-key="calls.select_employee_hint" />
        </div>
    </AppLayout>
</template>
