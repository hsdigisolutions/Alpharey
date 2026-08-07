<script setup>
/**
 * Attendance day entry (Screen 11 cell edit). Hourly mode auto-calculates
 * hours from check-in/out; project-based takes manual hours. The day total
 * is computed server-side from the frozen wage snapshot unless overridden.
 *
 * Feature 2 enhancements: searchable employee/project dropdowns (VCombobox),
 * project-workers-first grouping, live hours preview, wage rate display.
 */
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VCombobox from '@/Components/ui/VCombobox.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
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
    projectAssignments: { type: Object, default: () => ({}) }, // { projectId: [employeeId, ...] }
    canSeeWage: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const blank = {
    employee_id: '', project_id: '', date: null, mode: 'project_based', day_type: 'full',
    check_in: '09:00', check_out: '17:00', break_hours: 1, deduct_break: true,
    hours_worked: 0, quantity: null, overtime_hours: 0, status: 'present', total_amount: null,
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
const dayTypes = ['full', 'half', 'hourly', 'per_meter'];

// The day type drives pay; the capture mode follows it (hourly clock vs manual).
const isHourly = computed(() => form.day_type === 'hourly');
const isPerMeter = computed(() => form.day_type === 'per_meter');

// ── Grouped employee options for VCombobox ─────────────────────────────────
// When a project is selected: show project workers first (with header), then
// a divider, then all others. Without a project: flat list.
const employeeOptions = computed(() => {
    const pid = form.project_id;
    const all = props.employees;

    if (!pid) {
        return all.map((e) => ({ value: e.id, label: e.full_name, secondary: e.designation }));
    }

    const assignedIds = new Set(props.projectAssignments[pid] ?? []);
    const assigned = all.filter((e) => assignedIds.has(e.id));
    const others = all.filter((e) => !assignedIds.has(e.id));
    const opts = [];

    if (assigned.length) {
        opts.push({ isHeader: true, label: $tStr('attendance.project_workers') });
        assigned.forEach((e) => opts.push({ value: e.id, label: e.full_name, secondary: e.designation }));
    }
    if (others.length) {
        opts.push({ isHeader: true, label: $tStr('attendance.other_employees') });
        others.forEach((e) => opts.push({ value: e.id, label: e.full_name, secondary: e.designation }));
    }
    return opts;
});

// Project options for VCombobox.
const projectOptions = computed(() =>
    props.projects.map((p) => ({ value: p.id, label: p.name, secondary: p.client_name })),
);

// ── Live hours preview ─────────────────────────────────────────────────────
// Mirrors AttendanceService::hoursFromClock() so the user sees the result
// before saving. Server recomputes authoritatively on save.
const liveHours = computed(() => {
    if (!isHourly.value) return null;
    const ci = form.check_in, co = form.check_out;
    if (!ci || !co) return null;
    const [ih, im] = ci.split(':').map(Number);
    const [oh, om] = co.split(':').map(Number);
    const minutes = (oh * 60 + om) - (ih * 60 + im);
    if (minutes <= 0) return 0;
    let hours = minutes / 60;
    if (form.deduct_break && form.break_hours > 0) {
        hours = Math.max(0, hours - Number(form.break_hours));
    }
    return Math.round(hours * 100) / 100;
});

// ── Live pay preview by day type (canSeeWage only) ─────────────────────────
const selectedEmployee = computed(() =>
    props.employees.find((e) => e.id == form.employee_id) ?? null);

// The per-unit rate shown next to the preview, chosen by day type.
const dayTypeRate = computed(() => {
    const emp = selectedEmployee.value;
    if (!props.canSeeWage || !emp) return null;
    if (form.day_type === 'hourly') return emp.hourly_rate_raw ?? emp.hourly_rate ?? null;
    if (form.day_type === 'per_meter') return emp.per_meter_rate ?? null;
    return emp.daily_rate ?? null; // full / half
});

const liveTotal = computed(() => {
    const rate = dayTypeRate.value;
    if (rate === null) return null;
    let total = 0;
    if (form.day_type === 'full') total = rate;
    else if (form.day_type === 'half') total = rate * 0.5;
    else if (form.day_type === 'hourly') total = (liveHours.value ?? (Number(form.hours_worked) || 0)) * rate;
    else if (form.day_type === 'per_meter') total = (Number(form.quantity) || 0) * rate;
    return Math.round(total * 100) / 100;
});

const rateUnit = computed(() => {
    if (form.day_type === 'hourly') return '€/h';
    if (form.day_type === 'per_meter') return '€/m';
    return '€/día';
});

// ── Helpers for bilingual header strings outside <template> ───────────────
// $t is only available inside template; here we pull from the page prop.
function $tStr(key) {
    // Fallback: just use the last segment as a reasonable English label.
    return key.split('.').at(-1)?.replace(/_/g, ' ') ?? key;
}

function submit() {
    const payload = form.transform((d) => ({
        ...d,
        project_id: d.project_id || null,
        // Capture mode follows the day type: hourly clocks in/out, the rest are manual.
        mode: d.day_type === 'hourly' ? 'hourly' : 'project_based',
        quantity: d.day_type === 'per_meter' ? d.quantity : null,
    }));
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    props.record?.id ? payload.put(`/attendance/${props.record.id}`, opts) : payload.post('/attendance', opts);
}

const confirmDelete = ref(false);

function destroy() {
    if (props.record?.id) {
        confirmDelete.value = true;
    }
}

function doDestroy() {
    confirmDelete.value = false;
    router.delete(`/attendance/${props.record.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}

function mapsUrl(loc) {
    return `https://maps.google.com/maps?q=${loc.lat},${loc.lng}&z=16`;
}

function formatCoords(loc) {
    return `${(+loc.lat).toFixed(5)}°N, ${(+loc.lng).toFixed(5)}°E`;
}
</script>

<template>
    <VModal :open="open" :title-key="record?.id ? 'attendance.edit' : 'attendance.new'" size="md" @close="emit('close')">
        <form id="att-form" class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <!-- Searchable project dropdown (Feature 2) -->
                <FormField k="attendance.project" :error="form.errors.project_id">
                    <VCombobox
                        v-model="form.project_id"
                        :options="projectOptions"
                        :placeholder="$t('attendance.search_project')"
                        :search-placeholder="$t('attendance.search_project')"
                    />
                </FormField>

                <!-- Searchable employee dropdown with project-first grouping (Feature 2) -->
                <FormField k="attendance.employee" :error="form.errors.employee_id" required>
                    <VCombobox
                        v-model="form.employee_id"
                        :options="employeeOptions"
                        :placeholder="$t('attendance.search_employee')"
                        :search-placeholder="$t('attendance.search_employee')"
                        :disabled="Boolean(record?.id)"
                    />
                </FormField>

                <FormField k="attendance.date" :error="form.errors.date" required>
                    <VDateInput v-model="form.date" />
                </FormField>
                <FormField k="attendance.day_type" required>
                    <VSelect v-model="form.day_type">
                        <option v-for="dt in dayTypes" :key="dt" :value="dt">{{ $t(`attendance.day_type_${dt}`) }}</option>
                    </VSelect>
                </FormField>

                <template v-if="isHourly">
                    <FormField k="attendance.check_in" :error="form.errors.check_in"><VDateInput v-model="form.check_in" type="time" /></FormField>
                    <FormField k="attendance.check_out" :error="form.errors.check_out"><VDateInput v-model="form.check_out" type="time" /></FormField>
                    <FormField k="attendance.break_hours"><VInput v-model="form.break_hours" type="number" step="0.5" /></FormField>
                    <div class="flex items-end pb-2"><VCheckbox v-model="form.deduct_break"><Bilingual k="attendance.deduct_break" inline class="text-sm" /></VCheckbox></div>
                    <FormField k="attendance.overtime_hours" :error="form.errors.overtime_hours">
                        <VInput v-model="form.overtime_hours" type="number" step="0.25" />
                    </FormField>
                </template>
                <FormField v-else-if="isPerMeter" k="attendance.quantity" :error="form.errors.quantity" required>
                    <VInput v-model="form.quantity" type="number" step="0.01" />
                </FormField>

                <FormField k="attendance.status" required>
                    <VSelect v-model="form.status">
                        <option v-for="s in statuses" :key="s" :value="s">{{ $t(`attendance.status_${s}`) }}</option>
                    </VSelect>
                </FormField>
            </div>

            <!-- Live pay preview by day type — create flow, wage viewers -->
            <div v-if="canSeeWage && !record?.id && dayTypeRate !== null"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md bg-surface-sunken px-3 py-2 text-sm">
                <div v-if="isHourly" class="flex items-center gap-1.5 text-ink-soft">
                    <AppIcon name="clock" class="h-4 w-4" />
                    <Bilingual k="attendance.hours_preview" inline />
                    <span class="tabular-nums font-semibold text-ink">{{ liveHours ?? form.hours_worked }}h</span>
                </div>
                <div class="flex items-center gap-1.5 text-ink-soft">
                    <Bilingual k="attendance.wage_rate" inline />
                    <span class="tabular-nums text-ink">{{ dayTypeRate }} {{ rateUnit }}</span>
                </div>
                <div v-if="liveTotal !== null" class="ms-auto flex items-center gap-1.5 font-medium text-ink">
                    ≈ <span class="tabular-nums">{{ liveTotal.toFixed(2) }} €</span>
                </div>
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

            <!-- Worker PWA capture: shown only for a phone punch. Read-only. -->
            <div v-if="record?.worker" class="rounded-md border border-line bg-surface-sunken p-3">
                <div class="mb-2 flex items-center gap-2">
                    <VBadge status="info"><Bilingual k="attendance.from_app" inline /></VBadge>
                    <span v-if="record.worker.location_denied" class="text-xs text-status-warn">
                        <Bilingual k="attendance.no_location" inline />
                    </span>
                    <span v-if="record.worker.location_mismatch" class="text-xs text-status-warn font-medium">
                        ⚠ <Bilingual k="attendance.location_mismatch" inline />
                    </span>
                </div>

                <dl class="space-y-1.5 text-sm">
                    <div v-if="record.worker.check_in" class="flex items-start justify-between gap-3">
                        <dt class="text-ink-soft"><Bilingual k="attendance.punch_in_loc" inline /></dt>
                        <dd class="text-right">
                            <a :href="mapsUrl(record.worker.check_in)" target="_blank" rel="noopener"
                                class="text-accent underline-offset-2 hover:underline">
                                <Bilingual k="attendance.view_map" inline />
                            </a>
                            <span v-if="record.worker.check_in.accuracy" class="ms-1 text-xs text-muted">±{{ Math.round(record.worker.check_in.accuracy) }}m</span>
                            <p class="mt-0.5 font-mono text-[11px] text-muted">{{ formatCoords(record.worker.check_in) }}</p>
                        </dd>
                    </div>
                    <div v-if="record.worker.check_out" class="flex items-start justify-between gap-3">
                        <dt class="text-ink-soft"><Bilingual k="attendance.punch_out_loc" inline /></dt>
                        <dd class="text-right">
                            <a :href="mapsUrl(record.worker.check_out)" target="_blank" rel="noopener"
                                class="text-accent underline-offset-2 hover:underline">
                                <Bilingual k="attendance.view_map" inline />
                            </a>
                            <span v-if="record.worker.check_out.accuracy" class="ms-1 text-xs text-muted">±{{ Math.round(record.worker.check_out.accuracy) }}m</span>
                            <p class="mt-0.5 font-mono text-[11px] text-muted">{{ formatCoords(record.worker.check_out) }}</p>
                        </dd>
                    </div>
                    <div v-if="record.worker.note" class="pt-1">
                        <dt class="text-ink-soft"><Bilingual k="attendance.worker_note" inline /></dt>
                        <dd class="mt-0.5 rounded bg-surface-raised px-2 py-1 text-sm">{{ record.worker.note }}</dd>
                    </div>
                </dl>

                <div v-if="record.worker.has_photo" class="mt-3 overflow-hidden rounded-lg border border-line">
                    <div class="relative bg-black">
                        <img :src="`/attendance/${record.id}/selfie`" alt="Check-in selfie"
                            class="w-full object-cover object-top"
                            style="max-height: 340px;" />
                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 via-black/60 to-transparent px-3 pb-3 pt-10">
                            <div class="flex items-end gap-2.5">
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
                                    <p v-if="record.worker.check_in" class="text-sm font-semibold leading-tight text-white">
                                        Lat {{ (+record.worker.check_in.lat).toFixed(6) }}°
                                        Lng {{ (+record.worker.check_in.lng).toFixed(6) }}°
                                    </p>
                                    <p v-else class="text-xs text-yellow-300">GPS not captured</p>
                                    <p v-if="record.worker.check_in_at" class="mt-0.5 text-[11px] text-white/80">
                                        {{ record.worker.check_in_at }}
                                    </p>
                                    <p v-if="record.worker.check_in?.accuracy" class="text-[10px] text-white/60">
                                        ±{{ Math.round(record.worker.check_in.accuracy) }}m
                                    </p>
                                </div>
                                <div class="shrink-0 text-right leading-none">
                                    <p class="text-[9px] font-bold uppercase tracking-wider text-white/50">AlphaRey</p>
                                    <p class="text-[8px] text-white/35">GPS Check-in</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <a :href="`/attendance/${record.id}/selfie`" target="_blank" rel="noopener"
                        class="flex items-center justify-center gap-1.5 bg-surface-sunken py-1.5 text-xs text-accent underline-offset-2 hover:underline">
                        <Bilingual k="attendance.view_selfie_full" inline />
                    </a>
                </div>
            </div>

            <!-- Voice/text note captured at check-out. Read-only. -->
            <div v-if="record?.voice_note" class="rounded-md border border-line bg-surface-sunken p-3">
                <div class="mb-2 flex items-center gap-2">
                    <AppIcon name="mic" class="h-4 w-4 text-ink-soft" />
                    <Bilingual k="attendance.voice_note" inline class="text-sm font-medium" />
                    <span v-if="record.voice_note.duration_seconds" class="text-xs text-muted">
                        {{ record.voice_note.duration_seconds }}s
                    </span>
                </div>
                <p v-if="record.voice_note.text_note"
                    class="rounded bg-surface-raised px-2 py-1 text-sm">{{ record.voice_note.text_note }}</p>
                <audio v-if="record.voice_note.has_audio" controls preload="none"
                    :src="`/attendance/voice-notes/${record.voice_note.id}/download`"
                    class="mt-2 w-full"></audio>
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

    <VConfirmDialog
        :open="confirmDelete"
        :message="record ? `${record.employee ?? ''} — ${record.date ?? ''}` : ''"
        @confirm="doDestroy"
        @cancel="confirmDelete = false"
    />
</template>
