<script setup>
/**
 * Screen 24 — Measurements. Approve/reject workflow; approved rows feed
 * project billing (Phase 6). Create/edit in a modal.
 */
import { reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
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
    types: { type: Array, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({
    project_id: props.filters.project_id ?? '',
    approval: props.filters.approval ?? '',
    per_page: Number(props.filters.per_page ?? 25),
});
function apply(extra = {}) { router.get('/measurements', { ...filters, ...extra }, { preserveScroll: true, preserveState: true }); }

const showModal = ref(false);
const editing = ref(null);
const blank = { project_id: '', employee_id: '', date: null, quantity: null, unit: '', measurement_type: 'length', notes: '' };
const form = useForm({ ...blank });

function open(m = null) {
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
function approve(m, value) { router.post(`/measurements/${m.id}/approve`, { approved: value }, { preserveScroll: true }); }
function destroy(m) { router.delete(`/measurements/${m.id}`, { preserveScroll: true }); }

const columns = [
    { key: 'date', labelKey: 'measurements.date' },
    { key: 'project', labelKey: 'measurements.project' },
    { key: 'employee', labelKey: 'measurements.employee' },
    { key: 'quantity', labelKey: 'measurements.quantity', align: 'end' },
    { key: 'type', labelKey: 'measurements.type' },
    { key: 'approved', labelKey: 'measurements.approval' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
</script>

<template>
    <Head title="Mediciones" />
    <AppLayout>
        <VPageHeader k="measurements.title">
            <VButton v-if="can.create" icon="plus" @click="open()"><Bilingual k="measurements.new" inline /></VButton>
        </VPageHeader>

        <div class="flex flex-wrap items-end gap-2 pb-3">
            <VSelect v-model="filters.project_id" class="w-full sm:w-56" @update:model-value="apply()">
                <option value="">{{ $page.props.lang.es.measurements.project }}</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </VSelect>
            <VSelect v-model="filters.approval" class="w-full sm:w-52" @update:model-value="apply()">
                <option value="">{{ $page.props.lang.es.measurements.approval }}</option>
                <option value="approved">{{ $page.props.lang.es.measurements.is_approved }}</option>
                <option value="pending">{{ $page.props.lang.es.measurements.pending }}</option>
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
                    <VBadge :status="m.approved ? 'ok' : 'warn'">
                        <Bilingual :k="m.approved ? 'measurements.is_approved' : 'measurements.pending'" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5 text-end">
                    <span class="flex items-center justify-end gap-1.5">
                        <VButton v-if="can.approve && !m.approved" variant="ghost" size="sm" @click="approve(m, true)">
                            <Bilingual k="measurements.approve" inline />
                        </VButton>
                        <VButton v-if="can.approve && m.approved" variant="ghost" size="sm" @click="approve(m, false)">
                            <Bilingual k="measurements.reject" inline />
                        </VButton>
                        <VButton v-if="can.edit && !m.approved" variant="ghost" size="sm" icon="edit" @click="open(m)" />
                        <VButton v-if="can.delete && !m.approved" variant="ghost" size="sm" icon="trash" @click="destroy(m)" />
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
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="measurements.date" :error="form.errors.date" required><VDateInput v-model="form.date" /></FormField>
                <FormField k="measurements.quantity" :error="form.errors.quantity" required><VInput v-model="form.quantity" type="number" step="0.01" /></FormField>
                <FormField k="measurements.unit"><VInput v-model="form.unit" placeholder="m, m2, m3, kg" /></FormField>
                <FormField k="measurements.type" :error="form.errors.measurement_type" required>
                    <VSelect v-model="form.measurement_type">
                        <option v-for="t in types" :key="t" :value="t">{{ $page.props.lang.es.measurements[`type_${t}`] }}</option>
                    </VSelect>
                </FormField>
                <FormField k="measurements.notes" class="sm:col-span-2"><VTextarea v-model="form.notes" :rows="2" /></FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="meas-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
