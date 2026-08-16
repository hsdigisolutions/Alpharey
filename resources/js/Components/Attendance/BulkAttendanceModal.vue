<script setup>
/**
 * Bulk Attendance Entry wizard (Feature 1).
 * Step 1 — Project (optional)
 * Step 2 — Workers (multi-select, project-workers first)
 * Step 3 — Attendance details (same fields as single entry)
 * Step 4 — Preview & confirm
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VCombobox from '@/Components/ui/VCombobox.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    employees: { type: Array, required: true },
    projects: { type: Array, required: true },
    projectAssignments: { type: Object, default: () => ({}) },
    month: { type: String, required: true },
});
const emit = defineEmits(['close']);

// ── State ──────────────────────────────────────────────────────────────────
const step = ref(1);
const selectedProjectId = ref(null);
const selectedEmployeeIds = ref([]);
const workerSearch = ref('');

const form = useForm({
    date: null,
    mode: 'project_based',
    day_type: 'full',
    check_in: '09:00',
    check_out: '17:00',
    break_hours: 1,
    deduct_break: true,
    hours_worked: 8,
    quantity: null,
    weekend_rate_type: 'normal',
    weekend_rate_amount: null,
    overtime_hours: 0,
    status: 'present',
    notes: '',
});

// Reset on open
watch(() => props.open, (v) => {
    if (!v) return;
    step.value = 1;
    selectedProjectId.value = null;
    selectedEmployeeIds.value = [];
    workerSearch.value = '';
    form.clearErrors();
    form.date = `${props.month}-01`;
    form.mode = 'project_based';
    form.day_type = 'full';
    form.check_in = '09:00';
    form.check_out = '17:00';
    form.break_hours = 1;
    form.deduct_break = true;
    form.hours_worked = 8;
    form.quantity = null;
    form.weekend_rate_type = 'normal';
    form.weekend_rate_amount = null;
    form.overtime_hours = 0;
    form.status = 'present';
    form.notes = '';
});

// ── Project options ────────────────────────────────────────────────────────
const projectOptions = computed(() =>
    props.projects.map((p) => ({ value: p.id, label: p.name, secondary: p.client_name })),
);

// ── Worker list (grouped & filtered) ──────────────────────────────────────
const assignedToProject = computed(() => {
    if (!selectedProjectId.value) return new Set();
    return new Set(props.projectAssignments[selectedProjectId.value] ?? []);
});

const filteredEmployees = computed(() => {
    const q = workerSearch.value.trim().toLowerCase();
    if (!q) return props.employees;
    return props.employees.filter((e) =>
        e.full_name.toLowerCase().includes(q) ||
        (e.designation ?? '').toLowerCase().includes(q),
    );
});

const projectWorkers = computed(() =>
    filteredEmployees.value.filter((e) => assignedToProject.value.has(e.id)),
);
const otherWorkers = computed(() =>
    filteredEmployees.value.filter((e) => !assignedToProject.value.has(e.id)),
);
const showGroups = computed(() => selectedProjectId.value && projectWorkers.value.length > 0);

// ── Selection helpers ──────────────────────────────────────────────────────
function isSelected(id) { return selectedEmployeeIds.value.includes(id); }

function toggle(id) {
    const idx = selectedEmployeeIds.value.indexOf(id);
    if (idx === -1) selectedEmployeeIds.value.push(id);
    else selectedEmployeeIds.value.splice(idx, 1);
}

function selectAll() {
    selectedEmployeeIds.value = props.employees.map((e) => e.id);
}
function deselectAll() { selectedEmployeeIds.value = []; }
function selectGroup(list) {
    list.forEach((e) => { if (!isSelected(e.id)) selectedEmployeeIds.value.push(e.id); });
}

// ── Live hours (mirrors AttendanceService::hoursFromClock) ─────────────────
const dayTypes = ['full', 'half', 'hourly', 'per_meter'];
const isHourly = computed(() => form.day_type === 'hourly');
const isPerMeter = computed(() => form.day_type === 'per_meter');

const weekendRateTypes = ['normal', 'x1.5', 'x2', 'custom'];
const weekendKey = {
    normal: 'attendance.weekend_normal', 'x1.5': 'attendance.weekend_x15',
    x2: 'attendance.weekend_x2', custom: 'attendance.weekend_custom',
};
const isWeekendDate = computed(() => {
    if (!form.date) return false;
    const d = new Date(`${form.date}T00:00:00`).getDay();
    return d === 0 || d === 6;
});
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

// ── Preview ────────────────────────────────────────────────────────────────
const previewEmployees = computed(() =>
    selectedEmployeeIds.value.map((id) => props.employees.find((e) => e.id === id)).filter(Boolean),
);
const selectedProject = computed(() =>
    props.projects.find((p) => p.id == selectedProjectId.value) ?? null,
);

// ── Navigation ────────────────────────────────────────────────────────────
function next() { step.value = Math.min(step.value + 1, 4); }
function back() { step.value = Math.max(step.value - 1, 1); }

function submit() {
    const data = {
        employee_ids: selectedEmployeeIds.value,
        project_id: selectedProjectId.value,
        ...form.data(),
        // Capture mode follows the day type; drop per-meter quantity otherwise.
        mode: form.day_type === 'hourly' ? 'hourly' : 'project_based',
        quantity: form.day_type === 'per_meter' ? form.quantity : null,
        weekend_rate_type: isWeekendDate.value ? form.weekend_rate_type : null,
        weekend_rate_amount: isWeekendDate.value && form.weekend_rate_type === 'custom' ? form.weekend_rate_amount : null,
    };
    form.transform(() => data).post('/attendance/bulk', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

const statuses = ['present', 'absent', 'late', 'early_leave', 'leave'];
</script>

<template>
    <VModal :open="open" :title-key="'attendance.bulk_new'" size="lg" @close="emit('close')">
        <!-- Step indicator -->
        <div class="mb-5 flex items-center gap-1.5">
            <template v-for="n in 4" :key="n">
                <div class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold"
                    :class="n === step ? 'bg-accent text-on-accent' : n < step ? 'bg-accent-soft text-accent' : 'bg-surface-sunken text-muted'">
                    {{ n }}
                </div>
                <div v-if="n < 4" class="h-px flex-1"
                    :class="n < step ? 'bg-accent' : 'bg-line'" />
            </template>
        </div>

        <!-- ── Step 1: Project ──────────────────────────────────────────── -->
        <div v-if="step === 1" class="space-y-4">
            <p class="text-sm text-ink-soft"><Bilingual k="attendance.bulk_step1" /></p>
            <FormField k="attendance.project">
                <VCombobox
                    v-model="selectedProjectId"
                    :options="projectOptions"
                    :placeholder="$t('attendance.bulk_skip_project')"
                    :search-placeholder="$t('attendance.bulk_select_project')"
                />
            </FormField>
            <p class="text-xs text-muted"><Bilingual k="attendance.bulk_skip_project" inline /></p>
        </div>

        <!-- ── Step 2: Workers ─────────────────────────────────────────── -->
        <div v-else-if="step === 2" class="space-y-3">
            <div class="flex items-center gap-2">
                <VInput v-model="workerSearch" :placeholder="$t('attendance.search_employee')"
                    class="flex-1 text-sm" />
                <VButton variant="ghost" size="sm" @click="selectAll">
                    <Bilingual k="attendance.select_all" inline />
                </VButton>
                <VButton variant="ghost" size="sm" @click="deselectAll">
                    <Bilingual k="attendance.deselect_all" inline />
                </VButton>
            </div>

            <div class="max-h-72 overflow-y-auto rounded-lg border border-line bg-surface-raised">
                <template v-if="showGroups">
                    <!-- Project workers group -->
                    <div class="border-b border-line px-3 pt-2 pb-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                                <Bilingual k="attendance.project_workers" inline />
                                ({{ projectWorkers.length }})
                            </span>
                            <button type="button" class="text-xs text-accent hover:underline"
                                @click="selectGroup(projectWorkers)">
                                <Bilingual k="attendance.select_all" inline />
                            </button>
                        </div>
                    </div>
                    <label v-for="emp in projectWorkers" :key="emp.id"
                        class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-surface-hover">
                        <input type="checkbox" :checked="isSelected(emp.id)"
                            class="h-4 w-4 rounded accent-accent"
                            @change="toggle(emp.id)" />
                        <span>
                            <span class="text-sm font-medium text-ink">{{ emp.full_name }}</span>
                            <span v-if="emp.designation" class="ms-1.5 text-xs text-muted">{{ emp.designation }}</span>
                        </span>
                    </label>
                    <p v-if="!projectWorkers.length" class="px-3 py-2 text-sm text-muted">
                        <Bilingual k="attendance.no_project_workers" inline />
                    </p>

                    <!-- Others group -->
                    <div v-if="otherWorkers.length" class="border-y border-line px-3 pt-2 pb-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                                <Bilingual k="attendance.other_employees" inline />
                                ({{ otherWorkers.length }})
                            </span>
                            <button type="button" class="text-xs text-accent hover:underline"
                                @click="selectGroup(otherWorkers)">
                                <Bilingual k="attendance.select_all" inline />
                            </button>
                        </div>
                    </div>
                    <label v-for="emp in otherWorkers" :key="emp.id"
                        class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-surface-hover">
                        <input type="checkbox" :checked="isSelected(emp.id)"
                            class="h-4 w-4 rounded accent-accent"
                            @change="toggle(emp.id)" />
                        <span>
                            <span class="text-sm font-medium text-ink">{{ emp.full_name }}</span>
                            <span v-if="emp.designation" class="ms-1.5 text-xs text-muted">{{ emp.designation }}</span>
                        </span>
                    </label>
                </template>

                <!-- Flat list when no project -->
                <template v-else>
                    <label v-for="emp in filteredEmployees" :key="emp.id"
                        class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-surface-hover">
                        <input type="checkbox" :checked="isSelected(emp.id)"
                            class="h-4 w-4 rounded accent-accent"
                            @change="toggle(emp.id)" />
                        <span>
                            <span class="text-sm font-medium text-ink">{{ emp.full_name }}</span>
                            <span v-if="emp.designation" class="ms-1.5 text-xs text-muted">{{ emp.designation }}</span>
                        </span>
                    </label>
                </template>

                <p v-if="!filteredEmployees.length" class="px-3 py-3 text-sm text-muted">—</p>
            </div>

            <p class="text-xs text-muted">
                {{ selectedEmployeeIds.length }} <Bilingual k="attendance.bulk_workers_title" inline />
            </p>
        </div>

        <!-- ── Step 3: Details ─────────────────────────────────────────── -->
        <div v-else-if="step === 3" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField k="attendance.date" :error="form.errors.date" required>
                    <VDateInput v-model="form.date" />
                </FormField>
                <FormField k="attendance.status" required>
                    <VSelect v-model="form.status">
                        <option v-for="s in statuses" :key="s" :value="s">{{ $t(`attendance.status_${s}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="attendance.day_type" required>
                    <VSelect v-model="form.day_type">
                        <option v-for="dt in dayTypes" :key="dt" :value="dt">{{ $t(`attendance.day_type_${dt}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField v-if="isPerMeter" k="attendance.quantity" :error="form.errors.quantity" required>
                    <VInput v-model="form.quantity" type="number" step="0.01" />
                </FormField>

                <!-- Weekend / optional work day notice + rate (full width) -->
                <div v-if="isWeekendDate" class="rounded-md border border-accent/40 bg-accent-soft p-3 sm:col-span-2">
                    <p class="text-sm font-semibold text-accent"><Bilingual k="attendance.weekend_notice_title" /></p>
                    <p class="mt-1 text-xs text-ink-soft"><Bilingual k="attendance.weekend_notice_body" /></p>
                    <div class="mt-3">
                        <span class="mb-1.5 block text-[13px] font-medium"><Bilingual k="attendance.weekend_rate" /></span>
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            <label v-for="wt in weekendRateTypes" :key="wt" class="flex items-center gap-1.5 text-sm">
                                <input v-model="form.weekend_rate_type" type="radio" :value="wt" class="accent-[var(--color-accent)]" />
                                {{ $t(weekendKey[wt]) }}
                            </label>
                        </div>
                        <FormField v-if="form.weekend_rate_type === 'custom'" k="attendance.weekend_custom" class="mt-2">
                            <VCurrencyInput v-model="form.weekend_rate_amount" />
                        </FormField>
                    </div>
                </div>

                <template v-if="isHourly">
                    <FormField k="attendance.check_in" :error="form.errors.check_in">
                        <VDateInput v-model="form.check_in" type="time" />
                    </FormField>
                    <FormField k="attendance.check_out" :error="form.errors.check_out">
                        <VDateInput v-model="form.check_out" type="time" />
                    </FormField>
                    <FormField k="attendance.break_hours">
                        <VInput v-model="form.break_hours" type="number" step="0.5" />
                    </FormField>
                    <div class="flex items-end pb-2">
                        <VCheckbox v-model="form.deduct_break">
                            <Bilingual k="attendance.deduct_break" inline class="text-sm" />
                        </VCheckbox>
                    </div>
                    <FormField k="attendance.overtime_hours">
                        <VInput v-model="form.overtime_hours" type="number" step="0.25" />
                    </FormField>
                </template>
                <FormField v-else-if="!isPerMeter" k="attendance.hours_worked">
                    <VInput v-model="form.hours_worked" type="number" step="0.25" />
                </FormField>
            </div>

            <!-- Live hours preview -->
            <div v-if="isHourly && liveHours !== null"
                class="flex items-center gap-3 rounded-md bg-surface-sunken px-3 py-2 text-sm">
                <span class="text-ink-soft"><Bilingual k="attendance.hours_preview" inline /></span>
                <span class="tabular-nums font-semibold text-ink">{{ liveHours }}h</span>
                <span class="ms-auto text-xs text-muted">
                    × {{ selectedEmployeeIds.length }} <Bilingual k="common.employees" inline />
                </span>
            </div>

            <FormField k="attendance.notes"><VTextarea v-model="form.notes" :rows="2" /></FormField>
        </div>

        <!-- ── Step 4: Preview ─────────────────────────────────────────── -->
        <div v-else-if="step === 4" class="space-y-3">
            <div class="rounded-md bg-surface-sunken p-3 text-sm">
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-ink-soft">
                    <span><Bilingual k="attendance.date" inline /></span>
                    <span class="font-medium text-ink">{{ form.date }}</span>
                    <span><Bilingual k="attendance.project" inline /></span>
                    <span class="font-medium text-ink">{{ selectedProject?.name ?? '—' }}</span>
                    <span><Bilingual k="attendance.day_type" inline /></span>
                    <span class="font-medium text-ink">{{ $t(`attendance.day_type_${form.day_type}`) }}</span>
                    <span><Bilingual k="attendance.status" inline /></span>
                    <span class="font-medium text-ink">{{ $t(`attendance.status_${form.status}`) }}</span>
                    <template v-if="isPerMeter">
                        <span><Bilingual k="attendance.quantity" inline /></span>
                        <span class="tabular-nums font-medium text-ink">{{ form.quantity ?? '—' }}</span>
                    </template>
                    <template v-if="isHourly">
                        <span><Bilingual k="attendance.check_in" inline /></span>
                        <span class="font-medium text-ink">{{ form.check_in }} – {{ form.check_out }}</span>
                        <span><Bilingual k="attendance.hours_preview" inline /></span>
                        <span class="tabular-nums font-medium text-ink">{{ liveHours ?? '—' }}h</span>
                    </template>
                    <template v-else>
                        <span><Bilingual k="attendance.hours_worked" inline /></span>
                        <span class="tabular-nums font-medium text-ink">{{ form.hours_worked }}h</span>
                    </template>
                </div>
            </div>

            <p class="text-sm font-semibold text-ink-soft">
                {{ selectedEmployeeIds.length }} <Bilingual k="common.employees" inline />
            </p>
            <ul class="max-h-48 overflow-y-auto rounded-lg border border-line bg-surface-raised">
                <li v-for="emp in previewEmployees" :key="emp.id"
                    class="flex items-center gap-3 border-b border-line px-3 py-1.5 last:border-0">
                    <span class="text-sm font-medium text-ink">{{ emp.full_name }}</span>
                    <span v-if="emp.designation" class="text-xs text-muted">{{ emp.designation }}</span>
                </li>
            </ul>
        </div>

        <!-- Footer navigation -->
        <template #footer>
            <VButton variant="ghost" @click="step > 1 ? back() : emit('close')">
                <Bilingual :k="step > 1 ? 'common.back' : 'common.cancel'" inline />
            </VButton>
            <div class="flex-1" />

            <!-- Step 2: block if no workers selected -->
            <VButton v-if="step === 2" :disabled="!selectedEmployeeIds.length" @click="next">
                <Bilingual k="common.next" inline />
            </VButton>
            <!-- Steps 1 & 3: just advance -->
            <VButton v-else-if="step < 4" @click="next">
                <Bilingual k="common.next" inline />
            </VButton>
            <!-- Step 4: submit -->
            <VButton v-else :loading="form.processing" @click="submit">
                <Bilingual k="common.save" inline />
            </VButton>
        </template>
    </VModal>
</template>
