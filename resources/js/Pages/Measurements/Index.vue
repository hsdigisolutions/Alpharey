<script setup>
/**
 * Screen 24 — Measurements. Approve/reject workflow; approved rows feed
 * project billing (Phase 6). Create/edit in a modal.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    measurements: { type: Object, required: true },
    filters: { type: Object, required: true },
    projects: { type: Array, required: true },
    formProjects: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
    types: { type: Array, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({
    project_id: props.filters.project_id ?? '',
    status: props.filters.status ?? '',
    per_page: Number(props.filters.per_page ?? 25),
});
function apply(extra = {}) { router.get('/measurements', { ...filters, ...extra }, { preserveScroll: true, preserveState: true }); }
const exportUrl = (fmt) => `/measurements/${fmt}?` + new URLSearchParams(Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v != null))).toString();

const statusVariant = { pending: 'warn', approved: 'ok', rejected: 'danger' };

const showModal = ref(false);
const editing = ref(null);
const blank = { project_id: '', employee_id: '', date: null, quantity: null, unit: '', measurement_type: 'length', notes: '' };
const form = useForm({ ...blank });

/* Create-form project options: active only, plus the current project when
   editing a measurement whose project has since completed (Change 4). */
const projectOptions = computed(() => {
    const list = [...props.formProjects];
    const cur = form.project_id;
    if (cur && !list.some((p) => String(p.id) === String(cur))) {
        const found = props.projects.find((p) => String(p.id) === String(cur));
        if (found) list.push(found);
    }
    return list;
});

function open(m = null) {
    // Only creating needs a company context; editing an existing row is fine.
    if (m === null && !ensureCompanySelected()) return;

    editing.value = m;
    Object.keys(blank).forEach((k) => { form[k] = m?.[k] ?? blank[k]; });
    form.clearErrors();
    showModal.value = true;
}
function submit() {
    const payload = form.transform((d) => ({ ...d, employee_id: d.employee_id || null }));
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editing.value ? payload.put(`/measurements/${editing.value.id}`, opts) : payload.post('/measurements', opts);
}
const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }

function approve(m) { router.post(`/measurements/${m.id}/approve`, {}, { preserveScroll: true }); }
function reset(m) { router.post(`/measurements/${m.id}/reset`, {}, { preserveScroll: true }); }
function destroy(m) {
    askDelete(m.date ?? '', () => router.delete(`/measurements/${m.id}`, { preserveScroll: true }));
}

// Reject-with-reason modal.
const rejectForm = useForm({ rejection_reason: '' });
const rejecting = ref(null);
function openReject(m) { rejecting.value = m; rejectForm.reset(); rejectForm.clearErrors(); }
function submitReject() {
    rejectForm.post(`/measurements/${rejecting.value.id}/reject`, { preserveScroll: true, onSuccess: () => (rejecting.value = null) });
}

const columns = [
    { key: 'date', labelKey: 'measurements.date' },
    { key: 'project', labelKey: 'measurements.project' },
    { key: 'employee', labelKey: 'measurements.employee' },
    { key: 'quantity', labelKey: 'measurements.quantity', align: 'end' },
    { key: 'type', labelKey: 'measurements.type' },
    { key: 'status', labelKey: 'measurements.status' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
</script>

<template>
    <Head :title="$t('measurements.title')" />
    <AppLayout>
        <VPageHeader k="measurements.title">
            <a v-if="can.export" :href="exportUrl('export')" class="inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-surface-raised px-3 py-2 text-sm text-ink hover:bg-surface-hover">
                <AppIcon name="download" class="h-4 w-4" /> Excel
            </a>
            <a v-if="can.export" :href="exportUrl('export-pdf')" class="inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-surface-raised px-3 py-2 text-sm text-ink hover:bg-surface-hover">
                <AppIcon name="download" class="h-4 w-4" /> PDF
            </a>
            <VButton v-if="can.create" icon="plus" @click="open()"><Bilingual k="measurements.new" inline /></VButton>
        </VPageHeader>

        <div class="flex flex-wrap items-end gap-2 pb-3">
            <VSelect v-model="filters.project_id" class="w-full sm:w-56" @update:model-value="apply()">
                <option value="">{{ $t('measurements.project') }}</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </VSelect>
            <VSelect v-model="filters.status" class="w-full sm:w-52" @update:model-value="apply()">
                <option value="">{{ $t('measurements.status') }}</option>
                <option value="pending">{{ $t('measurements.pending') }}</option>
                <option value="approved">{{ $t('measurements.is_approved') }}</option>
                <option value="rejected">{{ $t('measurements.rejected_label') }}</option>
            </VSelect>
        </div>

        <VTable :columns="columns">
            <tr v-for="m in measurements.data" :key="m.id" class="hover:bg-surface-hover">
                <td class="tabular-nums px-3 py-2.5 text-sm">{{ m.date }}</td>
                <td class="px-3 py-2.5 text-sm">{{ m.project ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.employee ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ m.quantity }} {{ m.unit }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft"><Bilingual :k="`measurements.type_${m.measurement_type}`" inline /></td>
                <td class="px-3 py-2.5">
                    <VBadge :status="statusVariant[m.status]">
                        <Bilingual :k="`measurements.status_${m.status}`" inline />
                    </VBadge>
                    <span v-if="m.status === 'rejected' && m.rejection_reason" class="mt-0.5 block text-xs text-status-danger" :title="m.rejection_reason">
                        {{ m.rejection_reason.length > 40 ? m.rejection_reason.slice(0, 40) + '…' : m.rejection_reason }}
                    </span>
                </td>
                <td class="px-3 py-2.5 text-end">
                    <span class="flex items-center justify-end gap-1.5">
                        <VButton v-if="can.approve && m.status !== 'approved'" variant="ghost" size="sm" @click="approve(m)">
                            <Bilingual k="measurements.approve" inline />
                        </VButton>
                        <VButton v-if="can.approve && m.status === 'pending'" variant="ghost" size="sm" @click="openReject(m)">
                            <Bilingual k="measurements.reject" inline />
                        </VButton>
                        <VButton v-if="can.approve && m.status !== 'pending'" variant="ghost" size="sm" @click="reset(m)">
                            <Bilingual k="measurements.reopen" inline />
                        </VButton>
                        <VButton v-if="can.edit && m.status !== 'approved'" variant="ghost" size="sm" icon="edit" @click="open(m)" />
                        <VButton v-if="can.delete && m.status !== 'approved'" variant="ghost" size="sm" icon="trash" @click="destroy(m)" />
                    </span>
                </td>
            </tr>
            <template v-if="measurements.data.length === 0" #empty><VEmptyState icon="measurements" /></template>
        </VTable>

        <VPagination :page="measurements.current_page" :pages="measurements.last_page" :per-page="filters.per_page"
            :total="measurements.total" @update:page="(p) => apply({ page: p })" @update:per-page="(pp) => { filters.per_page = pp; apply(); }" />

        <VModal :open="showModal" :title-key="editing ? 'measurements.edit' : 'measurements.new'" @close="showModal = false">
            <form id="meas-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormField k="measurements.project" :error="form.errors.project_id" required>
                    <VSelect v-model="form.project_id">
                        <option value="">—</option>
                        <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="measurements.employee" :error="form.errors.employee_id">
                    <VSelect v-model="form.employee_id">
                        <option value="">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="measurements.date" :error="form.errors.date" required><VDateInput v-model="form.date" /></FormField>
                <FormField k="measurements.quantity" :error="form.errors.quantity" required><VInput v-model="form.quantity" type="number" step="0.01" /></FormField>
                <FormField k="measurements.unit"><VInput v-model="form.unit" placeholder="m, m2, m3, kg" /></FormField>
                <FormField k="measurements.type" :error="form.errors.measurement_type" required>
                    <VSelect v-model="form.measurement_type">
                        <option v-for="t in types" :key="t" :value="t">{{ $t(`measurements.type_${t}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="measurements.notes" class="sm:col-span-2"><VTextarea v-model="form.notes" :rows="2" /></FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="meas-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>
        <!-- Reject with a reason. -->
        <VModal :open="rejecting !== null" title-key="measurements.reject" @close="rejecting = null">
            <form id="reject-form" @submit.prevent="submitReject">
                <FormField k="measurements.rejection_reason" :error="rejectForm.errors.rejection_reason" required>
                    <VTextarea v-model="rejectForm.rejection_reason" :rows="3" :placeholder="$t('measurements.rejection_hint')" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="rejecting = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton variant="danger" type="submit" form="reject-form" :loading="rejectForm.processing"><Bilingual k="measurements.reject" inline /></VButton>
            </template>
        </VModal>

        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
