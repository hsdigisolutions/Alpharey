<script setup>
/**
 * Admin worker-session detail — a premium slide-over from the right. Dark hero
 * header with coral avatar initials, connected mileage boxes, before/after
 * condition photos (tap → full-screen lightbox), take/return voice players, and
 * fuel / fines / notes sections that hide themselves when there is no data.
 */
import { computed, onUnmounted, ref, watch } from 'vue';
import { fixAudioDuration } from '@/utils/audioDuration';

const props = defineProps({
    session: { type: Object, default: null }, // null = closed
});
const emit = defineEmits(['close']);

const lightbox = ref(null); // photo url shown full-screen, or null

const initials = computed(() => {
    const name = props.session?.employee ?? '?';
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
});

function fmtDuration(min) {
    if (min == null) return '—';
    if (min < 60) return `${min} min`;
    return `${Math.floor(min / 60)}h ${String(min % 60).padStart(2, '0')}m`;
}
function fmtVoice(sec) {
    if (!sec) return '';
    return `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;
}
function km(v) {
    return v != null ? `${Number(v).toLocaleString('es-ES')} km` : '—';
}
function eur(v) {
    return `${Number(v).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

function onKey(e) {
    if (e.key !== 'Escape') return;
    if (lightbox.value) lightbox.value = null;
    else if (props.session) emit('close');
}
watch(() => props.session, (s) => {
    lightbox.value = null;
    document.documentElement.classList.toggle('overflow-hidden', Boolean(s));
    if (s) window.addEventListener('keydown', onKey);
    else window.removeEventListener('keydown', onKey);
});
onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    document.documentElement.classList.remove('overflow-hidden');
});
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="session" class="fixed inset-0 z-40 overflow-hidden bg-black/40 backdrop-blur-sm" @click.self="emit('close')">
            <Transition
                enter-active-class="transition-transform duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0"
                leave-active-class="transition-transform duration-200 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full">
                <aside v-if="session"
                    class="absolute inset-y-0 end-0 flex w-full max-w-md flex-col overflow-y-auto overflow-x-hidden bg-surface-raised shadow-overlay">
                    <!-- Dark hero header -->
                    <header class="bg-sidebar px-5 py-5 text-white">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-accent text-base font-semibold text-on-accent">
                                    {{ initials }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-lg font-semibold leading-tight">{{ session.employee ?? '—' }}</p>
                                    <p class="truncate text-sm text-white/60">{{ session.vehicle_name }}</p>
                                </div>
                            </div>
                            <button type="button" class="shrink-0 rounded-md p-1 text-white/60 hover:text-white" aria-label="Close" @click="emit('close')">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                            </button>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-2">
                            <p class="tabular-nums min-w-0 truncate text-xs text-white/60">{{ session.taken_at }} → {{ session.returned_at ?? '—' }}</p>
                            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="session.open ? 'bg-status-warn-soft text-status-warn' : 'bg-status-ok-soft text-status-ok'">
                                {{ session.open ? $t('vehicles.session_open') : $t('vehicles.session_closed') }}
                            </span>
                        </div>
                    </header>

                    <div class="space-y-6 p-5">
                        <!-- Mileage -->
                        <section>
                            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $t('vehicles.session_mileage') }}</p>
                            <div class="flex items-center gap-2">
                                <div class="flex-1 rounded-lg border border-line bg-surface-sunken p-3 text-center">
                                    <p class="text-[11px] uppercase tracking-wide text-muted">{{ $t('vehicles.session_start') }}</p>
                                    <p class="tabular-nums mt-0.5 text-lg font-semibold text-ink">{{ km(session.starting_mileage) }}</p>
                                </div>
                                <span class="text-xl text-muted">→</span>
                                <div class="flex-1 rounded-lg border border-line bg-surface-sunken p-3 text-center">
                                    <p class="text-[11px] uppercase tracking-wide text-muted">{{ $t('vehicles.session_end') }}</p>
                                    <p class="tabular-nums mt-0.5 text-lg font-semibold text-ink">{{ km(session.ending_mileage) }}</p>
                                </div>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-sm text-ink-soft">
                                <span>{{ $t('vehicles.session_distance') }}: <span class="tabular-nums font-medium text-ink">{{ km(session.km_driven) }}</span></span>
                                <span>{{ $t('vehicles.session_duration') }}: <span class="tabular-nums font-medium text-ink">{{ fmtDuration(session.duration_minutes) }}</span></span>
                            </div>
                        </section>

                        <!-- Vehicle condition -->
                        <section class="border-t border-line pt-4">
                            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $t('vehicles.session_condition') }}</p>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <p class="mb-1 text-xs text-ink-soft">{{ $t('vehicles.session_before') }}</p>
                                    <button v-if="session.take_photo_url" type="button" class="block w-full overflow-hidden rounded-lg border border-line shadow-card" @click="lightbox = session.take_photo_url">
                                        <img :src="session.take_photo_url" alt="" class="h-28 w-full object-cover" />
                                    </button>
                                    <p v-else class="rounded-lg border border-dashed border-line px-3 py-6 text-center text-xs text-muted">{{ $t('vehicles.session_not_recorded') }}</p>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs text-ink-soft">{{ $t('vehicles.session_after') }}</p>
                                    <button v-if="session.return_photo_url" type="button" class="block w-full overflow-hidden rounded-lg border border-line shadow-card" @click="lightbox = session.return_photo_url">
                                        <img :src="session.return_photo_url" alt="" class="h-28 w-full object-cover" />
                                    </button>
                                    <p v-else class="rounded-lg border border-dashed border-line px-3 py-6 text-center text-xs text-muted">{{ $t('vehicles.session_not_recorded') }}</p>
                                </div>
                            </div>
                            <div class="mt-3 space-y-2">
                                <div>
                                    <p class="mb-1 text-xs text-ink-soft">{{ $t('vehicles.session_take_note') }} <span v-if="session.take_voice_duration" class="tabular-nums text-muted">· {{ fmtVoice(session.take_voice_duration) }}</span></p>
                                    <audio v-if="session.take_voice_url" :src="session.take_voice_url" controls preload="metadata" class="h-9 w-full" @loadedmetadata="fixAudioDuration($event.target)"></audio>
                                    <p v-else class="text-xs text-muted">{{ $t('vehicles.session_not_recorded') }}</p>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs text-ink-soft">{{ $t('vehicles.session_return_note') }} <span v-if="session.return_voice_duration" class="tabular-nums text-muted">· {{ fmtVoice(session.return_voice_duration) }}</span></p>
                                    <audio v-if="session.return_voice_url" :src="session.return_voice_url" controls preload="metadata" class="h-9 w-full" @loadedmetadata="fixAudioDuration($event.target)"></audio>
                                    <p v-else class="text-xs text-muted">{{ $t('vehicles.session_not_recorded') }}</p>
                                </div>
                            </div>
                        </section>

                        <!-- Fuel (hidden when none) -->
                        <section v-if="session.fuel && session.fuel.length" class="border-t border-line pt-4">
                            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $t('vehicles.session_fuel') }}</p>
                            <div v-for="(f, i) in session.fuel" :key="i" class="flex items-center justify-between gap-3 py-1 text-sm">
                                <span class="text-ink-soft">{{ f.date }}<template v-if="f.description"> · {{ f.description }}</template></span>
                                <span class="flex items-center gap-3">
                                    <span class="tabular-nums font-semibold text-ink">{{ eur(f.amount) }}</span>
                                    <a v-if="f.receipt_url" :href="f.receipt_url" target="_blank" rel="noopener" class="text-accent hover:underline">{{ $t('vehicles.session_receipt') }}</a>
                                </span>
                            </div>
                        </section>

                        <!-- Fines (hidden when none) -->
                        <section v-if="session.fines && session.fines.length" class="border-t border-line pt-4">
                            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $t('vehicles.session_fines') }}</p>
                            <div v-for="(f, i) in session.fines" :key="i" class="flex items-center justify-between gap-3 py-1 text-sm">
                                <span class="min-w-0 truncate text-ink-soft">{{ f.fine_date }}<template v-if="f.description"> · {{ f.description }}</template></span>
                                <span class="flex shrink-0 items-center gap-2">
                                    <span class="tabular-nums font-semibold text-ink">{{ eur(f.amount) }}</span>
                                    <span class="text-xs" :class="f.paid ? 'text-status-ok' : 'text-status-danger'">
                                        {{ f.paid ? $t('vehicles.fine_paid') : $t('vehicles.fine_pending') }}
                                    </span>
                                </span>
                            </div>
                        </section>

                        <!-- Notes (hidden when empty) -->
                        <section v-if="session.return_notes" class="border-t border-line pt-4">
                            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $t('vehicles.session_notes') }}</p>
                            <p class="rounded-md bg-surface-sunken px-3 py-2 text-sm text-ink">{{ session.return_notes }}</p>
                        </section>
                    </div>
                </aside>
            </Transition>

            <!-- Full-screen photo lightbox -->
            <div v-if="lightbox" class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4" @click="lightbox = null">
                <img :src="lightbox" alt="" class="max-h-full max-w-full rounded-lg object-contain" />
            </div>
        </div>
    </Transition>
</template>
