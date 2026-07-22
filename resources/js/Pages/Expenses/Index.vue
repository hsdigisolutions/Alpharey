<script setup>
/**
 * Screen 10 Gastos / Screen 09 Tab 6 — supplier and worker costs.
 *
 * The employee + project pair is load-bearing, not decorative: an expense
 * carrying both is a "worker project expense" and gets paid back through that
 * worker's payroll for the month. The form says so out loud rather than leaving
 * it as folklore.
 *
 * VAT + total are derived server-side; the preview here mirrors that.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VVatSelect from '@/Components/ui/VVatSelect.vue';

const props = defineProps({
    expenses: { type: Object, required: true },
    filters: { type: Object, required: true },
    vendors: { type: Array, required: true },
    projects: { type: Array, required: true },
    employees: { type: Array, required: true },
    categories: { type: Array, required: true },
    cards: { type: Array, required: true },
    types: { type: Array, required: true },
    vatOptions: { type: Array, required: true },
    paymentMethods: { type: Array, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({
    project_id: props.filters.project_id ?? '',
    vendor_id: props.filters.vendor_id ?? '',
    approval: props.filters.approval ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function apply(extra = {}) {
    router.get('/expenses', { ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}

/* ---------- create / edit ---------- */
const showModal = ref(false);
const editingId = ref(null);
const blank = {
    number: '', type: 'factura', expense_category_id: '', vendor_id: '', project_id: '',
    employee_id: '', company_card_id: '', date: null, due_date: null,
    subtotal: 0, vat_rate: null, payment_method: '', payment_status: 'unpaid',
    is_reimbursable: false, notes: '',
};
const form = useForm({ ...blank });

function openCreate() {
    // Expenses are company-owned; a company-less Super Admin picks one first.
    if (!ensureCompanySelected()) return;

    editingId.value = null;
    Object.keys(blank).forEach((k) => { form[k] = blank[k]; });
    form.clearErrors();
    showModal.value = true;
}

function submit() {
    const payload = form.transform((d) => ({
        ...d,
        expense_category_id: d.expense_category_id || null,
        vendor_id: d.vendor_id || null,
        project_id: d.project_id || null,
        employee_id: d.employee_id || null,
        company_card_id: d.company_card_id || null,
        payment_method: d.payment_method || null,
    }));
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value ? payload.post(`/expenses/${editingId.value}`, opts) : payload.post('/expenses', opts);
}

function approve(row, value) {
    router.post(`/expenses/${row.id}/approve`, { approved: value }, { preserveScroll: true });
}

function destroy(row) {
    router.delete(`/expenses/${row.id}`, { preserveScroll: true });
}

/* A worker project expense is paid back through payroll — say so in the form. */
const isWorkerProjectExpense = computed(() => Boolean(form.employee_id && form.project_id));

const preview = computed(() => {
    const subtotal = Number(form.subtotal) || 0;
    const rate = props.vatOptions.find((o) => o.value === form.vat_rate);
    const vat = rate?.percent ? subtotal * rate.percent / 100 : 0;
    return { vat, total: subtotal + vat };
});

function eur(n) {
    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(n ?? 0));
}

const columns = [
    { key: 'date', labelKey: 'expenses.date' },
    { key: 'number', labelKey: 'expenses.number' },
    { key: 'type', labelKey: 'expenses.type' },
    { key: 'vendor', labelKey: 'expenses.vendor' },
    { key: 'project', labelKey: 'expenses.project' },
    { key: 'employee', labelKey: 'expenses.employee' },
    { key: 'vat', labelKey: 'expenses.vat', align: 'end' },
    { key: 'total', labelKey: 'expenses.total', align: 'end' },
    { key: 'approval', labelKey: 'expenses.approval' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
</script>

<template>
    <Head :title="$t('expenses.title')" />
    <AppLayout>
        <VPageHeader k="expenses.title">
            <VButton v-if="can.create" icon="plus" @click="openCreate">
                <Bilingual k="expenses.new" inline />
            </VButton>
        </VPageHeader>

        <div class="grid grid-cols-2 gap-2 pb-3 lg:grid-cols-5">
            <VSelect v-model="filters.project_id" @update:model-value="apply()">
                <option value="">{{ $t('expenses.project') }}</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </VSelect>
            <VSelect v-model="filters.vendor_id" @update:model-value="apply()">
                <option value="">{{ $t('expenses.vendor') }}</option>
                <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
            </VSelect>
            <VSelect v-model="filters.approval" @update:model-value="apply()">
                <option value="">{{ $t('expenses.approval') }}</option>
                <option value="approved">{{ $t('expenses.is_approved') }}</option>
                <option value="pending">{{ $t('expenses.pending') }}</option>
            </VSelect>
            <VDateInput v-model="filters.from" @update:model-value="apply()" />
            <VDateInput v-model="filters.to" @update:model-value="apply()" />
        </div>

        <VTable :columns="columns">
            <tr v-for="r in expenses.data" :key="r.id" class="hover:bg-surface-hover">
                <td class="tabular-nums px-3 py-2.5 text-sm">{{ r.date }}</td>
                <td class="px-3 py-2.5 text-sm font-medium text-ink">{{ r.number ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">
                    <Bilingual :k="`expenses.type_${r.type}`" inline />
                </td>
                <td class="px-3 py-2.5 text-sm">{{ r.vendor ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ r.project ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">
                    {{ r.employee ?? '—' }}
                    <!-- flag the rows that will hit a payslip -->
                    <VBadge v-if="r.employee && r.project" status="info" class="ms-1">
                        <Bilingual k="nav.payroll" inline />
                    </VBadge>
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm text-ink-soft">
                    {{ r.vat_rate ? eur(r.vat_amount) : '—' }}
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm font-semibold">{{ eur(r.total) }}</td>
                <td class="px-3 py-2.5">
                    <VBadge :status="r.approved ? 'ok' : 'warn'">
                        <Bilingual :k="r.approved ? 'expenses.is_approved' : 'expenses.pending'" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5 text-end">
                    <span class="flex items-center justify-end gap-1.5">
                        <VButton v-if="can.approve && !r.approved" variant="ghost" size="sm" @click="approve(r, true)">
                            <Bilingual k="expenses.approve" inline />
                        </VButton>
                        <VButton v-if="can.approve && r.approved" variant="ghost" size="sm" @click="approve(r, false)">
                            <Bilingual k="expenses.reject" inline />
                        </VButton>
                        <VButton v-if="can.delete && !r.approved" variant="ghost" size="sm" icon="trash" @click="destroy(r)" />
                    </span>
                </td>
            </tr>
            <template v-if="expenses.data.length === 0" #empty>
                <VEmptyState icon="expenses" message-key="expenses.no_rows" />
            </template>
        </VTable>

        <VPagination :page="expenses.current_page" :pages="expenses.last_page" :total="expenses.total"
            @update:page="(p) => apply({ page: p })" />

        <VModal :open="showModal" :title-key="editingId ? 'expenses.edit' : 'expenses.new'" @close="showModal = false">
            <form id="expense-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormField k="expenses.type" :error="form.errors.type" required>
                    <VSelect v-model="form.type">
                        <option v-for="t in types" :key="t" :value="t">
                            {{ $t(`expenses.type_${t}`) }}
                        </option>
                    </VSelect>
                </FormField>
                <FormField k="expenses.number" :error="form.errors.number">
                    <VInput v-model="form.number" />
                </FormField>

                <FormField k="expenses.vendor" :error="form.errors.vendor_id">
                    <VSelect v-model="form.vendor_id">
                        <option value="">—</option>
                        <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="expenses.category" :error="form.errors.expense_category_id">
                    <VSelect v-model="form.expense_category_id">
                        <option value="">—</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </VSelect>
                </FormField>

                <FormField k="expenses.project" :error="form.errors.project_id">
                    <VSelect v-model="form.project_id">
                        <option value="">—</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="expenses.employee" :error="form.errors.employee_id">
                    <VSelect v-model="form.employee_id">
                        <option value="">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>

                <p v-if="isWorkerProjectExpense"
                    class="sm:col-span-2 rounded-md bg-status-info-soft px-3 py-2 text-xs text-status-info">
                    <Bilingual k="expenses.worker_project_hint" />
                </p>

                <FormField k="expenses.date" :error="form.errors.date" required>
                    <VDateInput v-model="form.date" />
                </FormField>
                <FormField k="expenses.due_date" :error="form.errors.due_date">
                    <VDateInput v-model="form.due_date" />
                </FormField>

                <FormField k="expenses.subtotal" :error="form.errors.subtotal" required>
                    <VInput v-model="form.subtotal" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="expenses.vat" :error="form.errors.vat_rate">
                    <VVatSelect v-model="form.vat_rate" :options="vatOptions" />
                </FormField>

                <FormField k="expenses.payment_method" :error="form.errors.payment_method">
                    <VSelect v-model="form.payment_method">
                        <option value="">—</option>
                        <option v-for="m in paymentMethods" :key="m" :value="m">
                            {{ $t(`employees.payment_${m}`) ?? m }}
                        </option>
                    </VSelect>
                </FormField>
                <FormField k="expenses.card" :error="form.errors.company_card_id">
                    <VSelect v-model="form.company_card_id">
                        <option value="">—</option>
                        <option v-for="c in cards" :key="c.id" :value="c.id">
                            {{ c.label }}{{ c.last_four ? ` ••${c.last_four}` : '' }}
                        </option>
                    </VSelect>
                </FormField>

                <label class="sm:col-span-2 flex items-center gap-2">
                    <VCheckbox v-model="form.is_reimbursable">
                        <Bilingual k="expenses.reimbursable" inline class="text-sm" />
                    </VCheckbox>
                </label>

                <dl class="tabular-nums sm:col-span-2 space-y-1 rounded-lg border border-line bg-surface-sunken p-3 text-sm">
                    <div v-if="form.vat_rate" class="flex justify-between">
                        <dt><Bilingual k="expenses.vat" inline /></dt>
                        <dd>{{ eur(preview.vat) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-line pt-1 font-bold">
                        <dt><Bilingual k="expenses.total" inline /></dt>
                        <dd>{{ eur(preview.total) }}</dd>
                    </div>
                </dl>

                <FormField k="expenses.notes" class="sm:col-span-2">
                    <VTextarea v-model="form.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="expense-form" :loading="form.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
