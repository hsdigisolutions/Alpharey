<script setup>
/**
 * Screen 12 — Cross-company employee deployments (the signature feature).
 * A host-company admin deploys an employee FROM another company onto one of
 * their own projects. Option A only: the employee stays on the home payroll;
 * the host is cross-charged automatically. Option B (host runs payroll) is
 * cesión ilegal and is never offered here.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
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
    deployments: { type: Object, required: true },
    filters: { type: Object, required: true },
    homeCompanies: { type: Array, required: true },
    projects: { type: Array, required: true },
    rateTypes: { type: Array, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({ status: props.filters.status ?? '' });
function apply(extra = {}) {
    router.get('/deployments', { ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}

const statusColor = { active: 'info', completed: 'ok', cancelled: 'neutral' };

// ---- Create modal -------------------------------------------------------
const showModal = ref(false);
const availableEmployees = ref([]);
const loadingEmployees = ref(false);
const blank = {
    home_company_id: '', employee_id: '', project_id: '', deployment_start: null,
    deployment_end: null, billing_method: 'option_a', rate_during_deployment: null,
    rate_type: 'hourly', split_pct: 100, notes: '',
};
const form = useForm({ ...blank });

function open() {
    // The host of a deployment is the acting company; pick one first.
    if (!ensureCompanySelected()) return;

    Object.keys(blank).forEach((k) => { form[k] = blank[k]; });
    availableEmployees.value = [];
    form.clearErrors();
    showModal.value = true;
}

async function loadEmployees() {
    form.employee_id = '';
    availableEmployees.value = [];
    if (!form.home_company_id) return;
    loadingEmployees.value = true;
    try {
        const res = await fetch(`/deployments/available-employees?home_company_id=${form.home_company_id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        availableEmployees.value = res.ok ? await res.json() : [];
    } finally {
        loadingEmployees.value = false;
    }
}

function submit() {
    form.post('/deployments', { preserveScroll: true, onSuccess: () => (showModal.value = false) });
}

// --- Edit (ACTIVE deployments only) — employee, rate structure, end date ---
const showEditModal = ref(false);
const editEmployees = ref([]);
const editForm = useForm({
    id: null, employee_id: '', rate_type: 'hourly', rate_during_deployment: null,
    split_pct: 100, deployment_end: null, notes: '',
});
// Context shown read-only in the edit modal (home/host/project/start are fixed).
const editContext = reactive({ home_company: '', host_company: '', project: '', start: '' });

async function openEdit(d) {
    editForm.clearErrors();
    editForm.id = d.id;
    editForm.employee_id = d.employee_id;
    editForm.rate_type = d.rate_type;
    editForm.rate_during_deployment = d.rate;
    editForm.split_pct = d.split_pct;
    editForm.deployment_end = d.end;
    editForm.notes = d.notes ?? '';
    Object.assign(editContext, { home_company: d.home_company, host_company: d.host_company, project: d.project, start: d.start });
    // Always keep the current employee selectable, even if the lookup below
    // is unavailable to an edit-only user.
    editEmployees.value = [{ id: d.employee_id, full_name: d.employee }];
    showEditModal.value = true;
    try {
        const res = await fetch(`/deployments/available-employees?home_company_id=${d.home_company_id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (res.ok) editEmployees.value = await res.json();
    } catch { /* keep the current-employee fallback */ }
}

function submitEdit() {
    editForm.put(`/deployments/${editForm.id}`, { preserveScroll: true, onSuccess: () => (showEditModal.value = false) });
}

function complete(d) { router.post(`/deployments/${d.id}/complete`, {}, { preserveScroll: true }); }
function cancel(d) { router.post(`/deployments/${d.id}/cancel`, {}, { preserveScroll: true }); }

const showCost = computed(() => props.deployments.data.some((d) => d.accrued_cost !== null));

const columns = [
    { key: 'employee', labelKey: 'deployments.employee' },
    { key: 'home', labelKey: 'deployments.home_company' },
    { key: 'host', labelKey: 'deployments.host_company' },
    { key: 'project', labelKey: 'deployments.project' },
    { key: 'period', labelKey: 'deployments.period' },
    { key: 'rate', labelKey: 'deployments.rate', align: 'end' },
    { key: 'cost', labelKey: 'deployments.accrued_cost', align: 'end' },
    { key: 'status', labelKey: 'deployments.status' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];

function eur(n) {
    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(n));
}
</script>

<template>
    <Head :title="$t('deployments.title')" />
    <AppLayout>
        <VPageHeader k="deployments.title">
            <VButton v-if="can.create" icon="plus" @click="open()"><Bilingual k="deployments.new" inline /></VButton>
        </VPageHeader>

        <div class="flex flex-wrap items-end gap-2 pb-3">
            <VSelect v-model="filters.status" class="w-full sm:w-52" @update:model-value="apply()">
                <option value="">{{ $t('deployments.all_statuses') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ $t(`deployments.status_${s}`) }}</option>
            </VSelect>
        </div>

        <VTable :columns="columns">
            <tr v-for="d in deployments.data" :key="d.id" class="hover:bg-surface-hover">
                <td class="px-3 py-2.5 text-sm font-medium text-ink">{{ d.employee ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ d.home_company ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ d.host_company ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm">{{ d.project ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-sm text-ink-soft">{{ d.start }} → {{ d.end ?? '…' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">
                    <span v-if="d.rate">{{ eur(d.rate) }}</span><span v-else>—</span>
                    <span class="text-muted"> / <Bilingual :k="`deployments.rate_${d.rate_type}`" inline /></span>
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">
                    <span v-if="showCost && d.accrued_cost !== null">{{ eur(d.accrued_cost) }}</span>
                    <span v-else class="text-muted">—</span>
                </td>
                <td class="px-3 py-2.5">
                    <VBadge :status="statusColor[d.status] ?? 'neutral'">
                        <Bilingual :k="`deployments.status_${d.status}`" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5 text-end">
                    <span class="flex items-center justify-end gap-1.5">
                        <VButton v-if="can.edit && d.status === 'active'" variant="ghost" size="sm" @click="openEdit(d)">
                            <Bilingual k="deployments.edit_action" inline />
                        </VButton>
                        <VButton v-if="can.edit && d.status === 'active'" variant="ghost" size="sm" @click="complete(d)">
                            <Bilingual k="deployments.complete" inline />
                        </VButton>
                        <VButton v-if="can.edit && d.status === 'active'" variant="ghost" size="sm" @click="cancel(d)">
                            <Bilingual k="deployments.cancel_action" inline />
                        </VButton>
                    </span>
                </td>
            </tr>
            <template v-if="deployments.data.length === 0" #empty><VEmptyState icon="deployments" /></template>
        </VTable>

        <VPagination :page="deployments.current_page" :pages="deployments.last_page"
            :total="deployments.total" @update:page="(p) => apply({ page: p })" />

        <VModal :open="showModal" title-key="deployments.new" @close="showModal = false">
            <form id="dep-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormField k="deployments.home_company" :error="form.errors.home_company_id" required>
                    <VSelect v-model="form.home_company_id" @update:model-value="loadEmployees">
                        <option value="">—</option>
                        <option v-for="c in homeCompanies" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.employee" :error="form.errors.employee_id" required>
                    <VSelect v-model="form.employee_id" :disabled="!form.home_company_id || loadingEmployees">
                        <option value="">{{ loadingEmployees ? '…' : '—' }}</option>
                        <option v-for="e in availableEmployees" :key="e.id" :value="e.id">
                            {{ e.full_name }}<template v-if="e.designation"> · {{ e.designation }}</template>
                        </option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.project" :error="form.errors.project_id" required>
                    <VSelect v-model="form.project_id">
                        <option value="">—</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.rate_type" :error="form.errors.rate_type" required>
                    <VSelect v-model="form.rate_type">
                        <option v-for="t in rateTypes" :key="t" :value="t">{{ $t(`deployments.rate_${t}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.start" :error="form.errors.deployment_start" required><VDateInput v-model="form.deployment_start" /></FormField>
                <FormField k="deployments.end" :error="form.errors.deployment_end"><VDateInput v-model="form.deployment_end" /></FormField>
                <FormField k="deployments.rate" :error="form.errors.rate_during_deployment"><VInput v-model="form.rate_during_deployment" type="number" step="0.01" min="0" /></FormField>
                <FormField k="deployments.split_pct" :error="form.errors.split_pct"><VInput v-model="form.split_pct" type="number" step="1" min="0" max="100" /></FormField>
                <FormField k="deployments.notes" class="sm:col-span-2"><VTextarea v-model="form.notes" :rows="2" /></FormField>

                <!-- A sentence, so NOT inline: the inline variant is nowrap and
                     would force the modal wider than the viewport. -->
                <p class="sm:col-span-2 rounded-md bg-status-info-soft px-3 py-2 text-xs text-status-info">
                    <Bilingual k="deployments.option_a_note" />
                </p>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="dep-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <!-- Edit (ACTIVE only): employee, rate structure, end date. Home/host/
             project/start are fixed once created and shown read-only. -->
        <VModal :open="showEditModal" title-key="deployments.edit_title" @close="showEditModal = false">
            <form id="dep-edit-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitEdit">
                <div class="sm:col-span-2 rounded-md bg-surface-sunken px-3 py-2 text-xs text-ink-soft">
                    <span class="font-medium">{{ editContext.home_company }} → {{ editContext.host_company }}</span>
                    · {{ editContext.project }} · {{ $t('deployments.start') }}: {{ editContext.start }}
                </div>
                <FormField k="deployments.employee" :error="editForm.errors.employee_id" required>
                    <VSelect v-model="editForm.employee_id">
                        <option v-for="e in editEmployees" :key="e.id" :value="e.id">
                            {{ e.full_name }}<template v-if="e.designation"> · {{ e.designation }}</template>
                        </option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.rate_type" :error="editForm.errors.rate_type" required>
                    <VSelect v-model="editForm.rate_type">
                        <option v-for="t in rateTypes" :key="t" :value="t">{{ $t(`deployments.rate_${t}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.rate" :error="editForm.errors.rate_during_deployment"><VInput v-model="editForm.rate_during_deployment" type="number" step="0.01" min="0" /></FormField>
                <FormField k="deployments.split_pct" :error="editForm.errors.split_pct"><VInput v-model="editForm.split_pct" type="number" step="1" min="0" max="100" /></FormField>
                <FormField k="deployments.end" :error="editForm.errors.deployment_end"><VDateInput v-model="editForm.deployment_end" /></FormField>
                <FormField k="deployments.notes" class="sm:col-span-2"><VTextarea v-model="editForm.notes" :rows="2" /></FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showEditModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="dep-edit-form" :loading="editForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
