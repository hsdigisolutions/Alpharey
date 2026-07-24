<script setup>
/**
 * Attendance day entry (Screen 11 cell edit). Hourly mode auto-calculates
 * hours from check-in/out; project-based takes manual hours. The day total
 * is computed server-side from the frozen wage snapshot unless overridden.
 */
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    record: { type: Object, default: null }, // existing record (edit) or null (create)
    presetEmployee: { type: Number, default: null },
    presetDate: { type: String, default: null },
    employees: { type: Array, required: true },
    projects: { type: Array, required: true },
    canSeeWage: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const blank = {
    employee_id: '', project_id: '', date: null, mode: 'hourly',
    check_in: '09:00', check_out: '17:00', break_hours: 1, deduct_break: true,
    hours_worked: 0, overtime_hours: 0, status: 'present', total_amount: null,
    manual_wage_override: false, is_paid: false, is_exception: false,
    exception_reason: '', notes: '',
};
const form = useForm({ ...blank });

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    Object.keys(blank).forEach((k) => { form[k] = props.record?.[k] ?? blank[k]; });
    if (!props.record) {
        form.employee_id = props.presetEmployee ?? '';
        form.date = props.presetDate ?? null;
    }
});

const statuses = ['present', 'absent', 'late', 'early_leave', 'leave'];
const isHourly = computed(() => form.mode === 'hourly');

function submit() {
    const payload = form.transform((d) => ({ ...d, project_id: d.project_id || null }));
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    props.record?.id ? payload.put(`/attendance/${props.record.id}`, opts) : payload.post('/attendance', opts);
}

function destroy() {
    if (props.record?.id) {
        router.delete(`/attendance/${props.record.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
    }
}

// A plain Google Maps link (client decision — no map tiles, so no CSP change).
// Opens in a new tab; a fabricated coordinate simply opens the wrong place, so
// this is never a security surface.
function mapsUrl(loc) {
    return `https://www.google.com/maps/search/?api=1&query=${loc.lat},${loc.lng}`;
}
</script>

<template>
    <VModal :open="open" :title-key="record?.id ? 'attendance.edit' : 'attendance.new'" size="md" @close="emit('close')">
        <form id="att-form" class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField k="attendance.employee" :error="form.errors.employee_id" required>
                    <VSelect v-model="form.employee_id" :disabled="Boolean(record?.id)">
                        <option value="">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="attendance.date" :error="form.errors.date" required>
                    <VDateInput v-model="form.date" />
                </FormField>
                <FormField k="attendance.project" :error="form.errors.project_id">
                    <VSelect v-model="form.project_id">
                        <option value="">—</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="attendance.mode" required>
                    <VSelect v-model="form.mode">
                        <option value="hourly">{{ $t('attendance.mode_hourly') }}</option>
                        <option value="project_based">{{ $t('attendance.mode_project_based') }}</option>
                    </VSelect>
                </FormField>

                <template v-if="isHourly">
                    <FormField k="attendance.check_in" :error="form.errors.check_in"><VDateInput v-model="form.check_in" type="time" /></FormField>
                    <FormField k="attendance.check_out" :error="form.errors.check_out"><VDateInput v-model="form.check_out" type="time" /></FormField>
                    <FormField k="attendance.break_hours"><VInput v-model="form.break_hours" type="number" step="0.5" /></FormField>
                    <div class="flex items-end pb-2"><VCheckbox v-model="form.deduct_break"><Bilingual k="attendance.deduct_break" inline class="text-sm" /></VCheckbox></div>
                </template>
                <FormField v-else k="attendance.hours_worked" :error="form.errors.hours_worked">
                    <VInput v-model="form.hours_worked" type="number" step="0.25" />
                </FormField>

                <FormField k="attendance.overtime_hours" :error="form.errors.overtime_hours">
                    <VInput v-model="form.overtime_hours" type="number" step="0.25" />
                </FormField>
                <FormField k="attendance.status" required>
                    <VSelect v-model="form.status">
                        <option v-for="s in statuses" :key="s" :value="s">{{ $t(`attendance.status_${s}`) }}</option>
                    </VSelect>
                </FormField>
            </div>

            <!-- Wage override (permission-gated) -->
            <div v-if="canSeeWage" class="rounded-md bg-surface-sunken p-3">
                <VCheckbox v-model="form.manual_wage_override"><Bilingual k="attendance.manual_override" inline class="text-sm" /></VCheckbox>
                <FormField v-if="form.manual_wage_override" k="attendance.total" class="mt-2"><VCurrencyInput v-model="form.total_amount" /></FormField>
            </div>

            <div class="flex flex-wrap gap-6">
                <VCheckbox v-model="form.is_paid"><Bilingual k="attendance.is_paid" inline class="text-sm" /></VCheckbox>
                <VCheckbox v-model="form.is_exception"><Bilingual k="attendance.is_exception" inline class="text-sm" /></VCheckbox>
            </div>
            <FormField v-if="form.is_exception" k="attendance.exception_reason" :error="form.errors.exception_reason" required>
                <VInput v-model="form.exception_reason" />
            </FormField>
            <FormField k="attendance.notes"><VTextarea v-model="form.notes" :rows="2" /></FormField>

            <!-- Worker PWA capture: shown only for a phone punch. Read-only —
                 an admin sees where/when the worker punched and their selfie,
                 but the record itself is edited through the fields above. -->
            <div v-if="record?.worker" class="rounded-md border border-line bg-surface-sunken p-3">
                <div class="mb-2 flex items-center gap-2">
                    <VBadge status="info"><Bilingual k="attendance.from_app" inline /></VBadge>
                    <span v-if="record.worker.location_denied" class="text-xs text-status-warn">
                        <Bilingual k="attendance.no_location" inline />
                    </span>
                </div>

                <dl class="space-y-1.5 text-sm">
                    <div v-if="record.worker.check_in" class="flex items-center justify-between gap-3">
                        <dt class="text-ink-soft"><Bilingual k="attendance.punch_in_loc" inline /></dt>
                        <dd>
                            <a :href="mapsUrl(record.worker.check_in)" target="_blank" rel="noopener"
                                class="text-accent underline-offset-2 hover:underline">
                                <Bilingual k="attendance.view_map" inline />
                            </a>
                            <span v-if="record.worker.check_in.accuracy" class="ms-1 text-xs text-muted">±{{ Math.round(record.worker.check_in.accuracy) }}m</span>
                        </dd>
                    </div>
                    <div v-if="record.worker.check_out" class="flex items-center justify-between gap-3">
                        <dt class="text-ink-soft"><Bilingual k="attendance.punch_out_loc" inline /></dt>
                        <dd>
                            <a :href="mapsUrl(record.worker.check_out)" target="_blank" rel="noopener"
                                class="text-accent underline-offset-2 hover:underline">
                                <Bilingual k="attendance.view_map" inline />
                            </a>
                            <span v-if="record.worker.check_out.accuracy" class="ms-1 text-xs text-muted">±{{ Math.round(record.worker.check_out.accuracy) }}m</span>
                        </dd>
                    </div>
                    <div v-if="record.worker.note" class="pt-1">
                        <dt class="text-ink-soft"><Bilingual k="attendance.worker_note" inline /></dt>
                        <dd class="mt-0.5 rounded bg-surface-raised px-2 py-1 text-sm">{{ record.worker.note }}</dd>
                    </div>
                </dl>

                <!-- Check-in selfie with GPS watermark (phone punch only).
                     Fetched through the gated, audited route — never a public URL.
                     Overlaid timestamp + coordinates mimic the GPS-camera format
                     so the admin sees the same proof the worker captured. -->
                <div v-if="record.worker.has_photo" class="mt-3 overflow-hidden rounded-lg border border-line">
                    <div class="relative bg-black">
                        <img :src="`/attendance/${record.id}/selfie`" alt="Check-in selfie"
                            class="w-full object-cover object-top"
                            style="max-height: 340px;" />
                        <!-- GPS-camera style overlay: dark gradient + data strip -->
                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 via-black/60 to-transparent px-3 pb-3 pt-10">
                            <div class="flex items-end gap-2.5">
                                <!-- Location pin box (mini-map substitute — no external tile allowed by CSP) -->
                                <div class="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded border border-white/20 bg-black/70">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        class="h-6 w-6 text-white/80">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                        <circle cx="12" cy="10" r="3" />
                                    </svg>
                                    <span class="mt-0.5 text-[8px] font-bold uppercase tracking-widest text-white/50">GPS</span>
                                </div>
                                <div class="min-w-0 flex-1 font-mono">
                                    <!-- Coordinates -->
                                    <p v-if="record.worker.check_in" class="text-sm font-semibold leading-tight text-white">
                                        Lat {{ (+record.worker.check_in.lat).toFixed(6) }}°
                                        Lng {{ (+record.worker.check_in.lng).toFixed(6) }}°
                                    </p>
                                    <p v-else class="text-xs text-yellow-300">GPS not captured</p>
                                    <!-- Timestamp -->
                                    <p v-if="record.worker.check_in_at" class="mt-0.5 text-[11px] text-white/80">
                                        {{ record.worker.check_in_at }}
                                    </p>
                                    <!-- Accuracy -->
                                    <p v-if="record.worker.check_in?.accuracy" class="text-[10px] text-white/60">
                                        ±{{ Math.round(record.worker.check_in.accuracy) }}m
                                    </p>
                                </div>
                                <!-- Branding badge -->
                                <div class="shrink-0 text-right leading-none">
                                    <p class="text-[9px] font-bold uppercase tracking-wider text-white/50">AlphaRey</p>
                                    <p class="text-[8px] text-white/35">GPS Check-in</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- View full size link below the photo -->
                    <a :href="`/attendance/${record.id}/selfie`" target="_blank" rel="noopener"
                        class="flex items-center justify-center gap-1.5 bg-surface-sunken py-1.5 text-xs text-accent underline-offset-2 hover:underline">
                        <Bilingual k="attendance.view_selfie_full" inline />
                    </a>
                </div>
            </div>
        </form>
        <template #footer>
            <VButton v-if="record?.id" variant="danger" size="sm" icon="trash" class="me-auto" @click="destroy">
                <Bilingual k="documents.delete" inline />
            </VButton>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="att-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
        </template>
    </VModal>
</template>
