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
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VKpiCard from '@/Components/ui/VKpiCard.vue';
import VModal from '@/Components/ui/VModal.vue';
import VehicleExpenseModal from '@/Components/Expenses/VehicleExpenseModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VVatSelect from '@/Components/ui/VVatSelect.vue';

const props = defineProps({
    expenses: { type: Object, required: true },
    stats: { type: Object, default: () => ({ total: { count: 0, amount: 0 }, approved: { count: 0, amount: 0 }, pending: { count: 0, amount: 0 } }) },
    filters: { type: Object, required: true },
    vendors: { type: Array, required: true },
    vehicles: { type: Array, default: () => [] },
    projects: { type: Array, required: true },
    formProjects: { type: Array, default: () => [] },
    employees: { type: Array, required: true },
    categories: { type: Array, required: true },
    cards: { type: Array, required: true },
    types: { type: Array, required: true },
    vatOptions: { type: Array, required: true },
    paymentMethods: { type: Array, required: true },
    paymentStatuses: { type: Array, required: true },
    bearableByOptions: { type: Array, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    project_id: props.filters.project_id ?? '',
    vendor_id: props.filters.vendor_id ?? '',
    type: props.filters.type ?? '',
    expense_category_id: props.filters.expense_category_id ?? '',
    payment_status: props.filters.payment_status ?? '',
    approval: props.filters.approval ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function apply(extra = {}) {
    router.get('/expenses', { ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}
function setApproval(val) { filters.approval = val; apply(); }

// Export the current filtered view (built as a computed so the query string is
// never assembled inline in the template).
const exportQuery = computed(() => new URLSearchParams(
    Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v != null)),
).toString());

/* ---------- create / edit ---------- */
const showModal = ref(false);
const showVehicleModal = ref(false);
const editingId = ref(null);
const editingApproved = ref(false); // approved expenses are view-only (locked server-side)
const blank = {
    number: '', type: 'factura', expense_category_id: '', vendor_id: '', project_id: '',
    employee_id: '', company_card_id: '', date: null, due_date: null,
    subtotal: 0, vat_rate: null, vat_custom_percent: null, payment_method: '', payment_status: 'unpaid',
    bearable_by: 'company', deduct_from_salary: false, notes: '', file: null,
};
const form = useForm({ ...blank });
const currentFile = ref(null);

function onFilePicked(event) {
    form.file = event.target.files?.[0] ?? null;
}

function openCreate() {
    // Expenses are company-owned; a company-less Super Admin picks one first.
    if (!ensureCompanySelected()) return;

    editingId.value = null;
    editingApproved.value = false;
    Object.keys(blank).forEach((k) => { form[k] = blank[k]; });
    splitMode.value = false;
    splits.value = [];
    currentFile.value = null;
    form.clearErrors();
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    editingApproved.value = row.approved;
    Object.assign(form, {
        number: row.number ?? '',
        type: row.type,
        expense_category_id: row.expense_category_id ?? '',
        vendor_id: row.vendor_id ?? '',
        project_id: row.project_id ?? '',
        employee_id: row.employee_id ?? '',
        company_card_id: row.company_card_id ?? '',
        date: row.date,
        due_date: row.due_date ?? null,
        subtotal: row.subtotal,
        vat_rate: row.vat_rate ?? null,
        vat_custom_percent: row.vat_custom_percent ?? null,
        payment_method: row.payment_method ?? '',
        payment_status: row.payment_status ?? 'unpaid',
        bearable_by: row.bearable_by ?? 'company',
        deduct_from_salary: row.deduct_from_salary ?? false,
        notes: row.notes ?? '',
        file: null,
    });
    if (row.is_split && row.splits?.length) {
        splitMode.value = true;
        splits.value = row.splits.map((s) => ({
            expense_category_id: s.expense_category_id ?? '', amount: s.amount, description: s.description ?? '',
        }));
    } else {
        splitMode.value = false;
        splits.value = [];
    }
    currentFile.value = row.original_name ?? null;
    form.clearErrors();
    showModal.value = true;
}

function submit() {
    // Block a split that does not reconcile to the total (server re-checks too).
    if (splitMode.value && !splitBalanced.value) return;

    const payload = form.transform((d) => ({
        ...d,
        // A split expense carries no single category — the breakdown is the rows.
        expense_category_id: splitMode.value ? null : (d.expense_category_id || null),
        vendor_id: d.vendor_id || null,
        project_id: d.project_id || null,
        employee_id: d.employee_id || null,
        company_card_id: d.company_card_id || null,
        payment_method: d.payment_method || null,
        splits: splitMode.value
            ? splits.value.map((s) => ({
                expense_category_id: s.expense_category_id || null,
                amount: Number(s.amount) || 0,
                description: s.description || null,
            }))
            : undefined,
    }));
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    editingId.value ? payload.post(`/expenses/${editingId.value}`, opts) : payload.post('/expenses', opts);
}

function approve(row, value) {
    router.post(`/expenses/${row.id}/approve`, { approved: value }, { preserveScroll: true });
}

function sendToReview(row) {
    router.post(`/expenses/${row.id}/review`, {}, { preserveScroll: true });
}

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }

function destroy(row) {
    askDelete(row.description ?? row.vendor ?? '',
        () => router.delete(`/expenses/${row.id}`, { preserveScroll: true }));
}

/* ---------- category management ---------- */
const showCategories = ref(false);
const activeCategories = computed(() => props.categories.filter((c) => c.active));
const categoryForm = useForm({ name: '' });

function addCategory() {
    categoryForm.post('/expense-categories', {
        preserveScroll: true,
        onSuccess: () => categoryForm.reset('name'),
    });
}
function toggleCategory(c) {
    router.put(`/expense-categories/${c.id}`, { name: c.name, active: !c.active }, { preserveScroll: true });
}
function deleteCategory(c) {
    router.delete(`/expense-categories/${c.id}`, { preserveScroll: true });
}

/* A worker project expense is paid back through payroll — say so in the form. */
const isWorkerProjectExpense = computed(() => Boolean(form.employee_id && form.project_id));

/* Create-form project options: active only, but keep the currently-selected
   project when editing an expense whose project has since completed (Change 4). */
const projectOptions = computed(() => {
    const list = [...props.formProjects];
    const cur = form.project_id;
    if (cur && !list.some((p) => String(p.id) === String(cur))) {
        const found = props.projects.find((p) => String(p.id) === String(cur));
        if (found) list.push(found);
    }
    return list;
});

const preview = computed(() => {
    const subtotal = Number(form.subtotal) || 0;
    const rate = props.vatOptions.find((o) => o.value === form.vat_rate);
    // A custom rate uses the typed %; every other rate uses its fixed %.
    const vatPct = form.vat_rate === 'custom' ? (Number(form.vat_custom_percent) || 0) : (rate?.percent || 0);
    const vat = subtotal * vatPct / 100;
    return { vat, total: subtotal + vat };
});

/* ---------- multi-category split (Smart Expense Split) ---------- */
const splitMode = ref(false);
const splits = ref([]);
const blankSplit = () => ({ expense_category_id: '', amount: 0, description: '' });
function toggleSplit(on) {
    splitMode.value = on;
    if (on && splits.value.length < 2) splits.value = [blankSplit(), blankSplit()];
}
function addSplitRow() { splits.value.push(blankSplit()); }
function removeSplitRow(i) { if (splits.value.length > 1) splits.value.splice(i, 1); }
const splitSum = computed(() => Math.round(splits.value.reduce((s, r) => s + (Number(r.amount) || 0), 0) * 100) / 100);
const splitRemaining = computed(() => Math.round((preview.value.total - splitSum.value) * 100) / 100);
const splitBalanced = computed(() => Math.abs(splitRemaining.value) < 0.005 && splits.value.length >= 2);
const splitPct = (amount) => {
    const t = preview.value.total || 0;
    return t > 0 ? Math.min(100, Math.round((Number(amount) || 0) / t * 100)) : 0;
};
function autoSplit() {
    const n = splits.value.length;
    if (!n) return;
    const total = preview.value.total;
    const base = Math.floor((total / n) * 100) / 100;
    splits.value.forEach((r, idx) => {
        r.amount = idx === n - 1 ? Math.round((total - base * (n - 1)) * 100) / 100 : base;
    });
}

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
            <a v-if="can.export" :href="`/expenses/export?${exportQuery}`"
                class="inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-surface-raised px-3 py-1.5 text-sm text-ink hover:bg-surface-hover">
                <AppIcon name="download" class="h-4 w-4" /> Excel
            </a>
            <a v-if="can.export" :href="`/expenses/export-pdf?${exportQuery}`"
                class="inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-surface-raised px-3 py-1.5 text-sm text-ink hover:bg-surface-hover">
                <AppIcon name="download" class="h-4 w-4" /> PDF
            </a>
            <VButton v-if="can.edit" variant="secondary" icon="settings" @click="showCategories = true">
                <Bilingual k="expenses.manage_categories" inline />
            </VButton>
            <VButton v-if="can.create" variant="secondary" icon="vehicles" @click="showVehicleModal = true">
                <Bilingual k="expenses.new_vehicle_expense" inline />
            </VButton>
            <VButton v-if="can.create" icon="plus" @click="openCreate">
                <Bilingual k="expenses.new" inline />
            </VButton>
        </VPageHeader>

        <!-- Summary cards — count + € per approval state. Clickable. -->
        <div class="mb-4 grid grid-cols-3 gap-3">
            <VKpiCard k="stats.total" :value="stats.total.count" :sub="eur(stats.total.amount)" clickable :active="filters.approval === ''" @click="setApproval('')" />
            <VKpiCard k="stats.approved" :value="stats.approved.count" :sub="eur(stats.approved.amount)" status="ok" clickable :active="filters.approval === 'approved'" @click="setApproval('approved')" />
            <VKpiCard k="stats.pending" :value="stats.pending.count" :sub="eur(stats.pending.amount)" status="warn" clickable :active="filters.approval === 'pending'" @click="setApproval('pending')" />
        </div>

        <div class="grid grid-cols-2 gap-2 pb-3 lg:grid-cols-4">
            <VInput v-model="filters.search" :placeholder="$t('expenses.search')"
                @keyup.enter="apply()" @blur="apply()" />
            <VSelect v-model="filters.type" @update:model-value="apply()">
                <option value="">{{ $t('expenses.all_types') }}</option>
                <option v-for="t in types" :key="t" :value="t">{{ $t(`expenses.type_${t}`) }}</option>
            </VSelect>
            <VSelect v-model="filters.expense_category_id" @update:model-value="apply()">
                <option value="">{{ $t('expenses.category') }}</option>
                <option v-for="c in activeCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </VSelect>
            <VSelect v-model="filters.payment_status" @update:model-value="apply()">
                <option value="">{{ $t('expenses.payment_status') }}</option>
                <option v-for="s in paymentStatuses" :key="s" :value="s">
                    {{ $t(`invoices.payment_${s}`) }}
                </option>
            </VSelect>
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
            <tr v-for="r in expenses.data" :key="r.id" class="cursor-pointer hover:bg-surface-hover"
                @click="openEdit(r)">
                <td class="tabular-nums px-3 py-2.5 text-sm">{{ r.date }}</td>
                <td class="px-3 py-2.5 text-sm font-medium text-ink">
                    {{ r.number ?? '—' }}
                    <VBadge v-if="r.source === 'worker_fuel'" status="info" class="ms-1" :title="$t('expenses.auto_fuel_hint')">
                        {{ $t('expenses.auto_fuel_badge') }}
                    </VBadge>
                    <VBadge v-if="r.is_split" status="neutral" class="ms-1">{{ $t('expenses.split_multiple') }}</VBadge>
                </td>
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
                    <VBadge v-if="r.review_status === 'in_review'" status="info">
                        <Bilingual k="expenses.in_review" inline />
                    </VBadge>
                    <VBadge v-else :status="r.approved ? 'ok' : 'warn'">
                        <Bilingual :k="r.approved ? 'expenses.is_approved' : 'expenses.pending'" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5 text-end" @click.stop>
                    <span class="flex items-center justify-end gap-1.5">
                        <a v-if="r.has_file" :href="`/expenses/${r.id}/receipt`" target="_blank" rel="noopener"
                            class="text-ink-soft hover:text-accent" :title="$t('expenses.download_receipt')">
                            <AppIcon name="file" class="h-4 w-4" />
                        </a>
                        <button v-if="can.edit && !r.approved" type="button"
                            class="rounded-sm p-1.5 text-muted hover:text-ink" :title="$t('expenses.edit')"
                            @click="openEdit(r)">
                            <AppIcon name="edit" class="h-3.5 w-3.5" />
                        </button>
                        <template v-if="can.approve_final && r.review_status !== 'in_review'">
                            <VButton v-if="!r.approved" variant="ghost" size="sm" @click="approve(r, true)">
                                <Bilingual k="expenses.approve" inline />
                            </VButton>
                            <VButton v-else variant="ghost" size="sm" @click="approve(r, false)">
                                <Bilingual k="expenses.reject" inline />
                            </VButton>
                            <VButton v-if="!r.approved" variant="ghost" size="sm" @click="sendToReview(r)">
                                <Bilingual k="expenses.send_to_review" inline />
                            </VButton>
                        </template>
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
                <FormField v-if="!splitMode" k="expenses.category" :error="form.errors.expense_category_id">
                    <VSelect v-model="form.expense_category_id">
                        <option value="">—</option>
                        <option v-for="c in activeCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </VSelect>
                    <label class="mt-1.5 flex items-center gap-2 text-xs text-ink-soft">
                        <input type="checkbox" :checked="splitMode" class="h-3.5 w-3.5 rounded-sm accent-[var(--color-accent)]"
                            @change="toggleSplit($event.target.checked)" />
                        {{ $t('expenses.split_toggle') }}
                    </label>
                </FormField>
                <div v-else class="rounded-md border border-line-strong bg-surface-sunken px-3 py-2">
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" checked class="h-4 w-4 rounded-sm accent-[var(--color-accent)]"
                            @change="toggleSplit($event.target.checked)" />
                        {{ $t('expenses.split_toggle') }}
                    </label>
                    <p class="mt-0.5 text-xs text-muted">{{ $t('expenses.split_breakdown') }} ↓</p>
                </div>

                <FormField k="expenses.project" :error="form.errors.project_id">
                    <VSelect v-model="form.project_id">
                        <option value="">—</option>
                        <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
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
                <FormField k="expenses.vat" :error="form.errors.vat_rate || form.errors.vat_custom_percent">
                    <VVatSelect v-model="form.vat_rate" v-model:custom-percent="form.vat_custom_percent" :options="vatOptions" />
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

                <FormField k="expenses.bearable_by" :error="form.errors.bearable_by" required>
                    <VSelect v-model="form.bearable_by">
                        <option v-for="b in bearableByOptions" :key="b" :value="b">
                            {{ $t(`expenses.bearable_${b}`) }}
                        </option>
                    </VSelect>
                </FormField>
                <label v-if="form.bearable_by === 'employee'" class="flex items-center gap-2 pt-6">
                    <VCheckbox v-model="form.deduct_from_salary">
                        <Bilingual k="expenses.deduct_from_salary" inline class="text-sm" />
                    </VCheckbox>
                </label>
                <p class="sm:col-span-2 -mt-2 text-xs text-muted">{{ $t('expenses.bearable_hint') }}</p>

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

                <!-- Smart Expense Split — distribute the total across categories -->
                <div v-if="splitMode" class="sm:col-span-2 space-y-2 rounded-lg border border-line-strong bg-surface-raised p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-ink">{{ $t('expenses.split_breakdown') }}</span>
                        <div class="flex items-center gap-2">
                            <VButton variant="ghost" size="sm" @click="autoSplit">{{ $t('expenses.split_auto') }}</VButton>
                            <span class="text-sm font-semibold" :class="splitBalanced ? 'text-status-ok' : 'text-status-danger'">
                                {{ $t('expenses.split_remaining') }}: {{ eur(splitRemaining) }} <span v-if="splitBalanced">✅</span>
                            </span>
                        </div>
                    </div>
                    <div v-for="(row, i) in splits" :key="i" class="grid grid-cols-12 items-center gap-2">
                        <VSelect v-model="row.expense_category_id" class="col-span-4">
                            <option value="">{{ $t('expenses.split_category') }}</option>
                            <option v-for="c in activeCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </VSelect>
                        <VInput v-model="row.amount" type="number" step="0.01" min="0" class="col-span-2" :placeholder="$t('expenses.split_amount')" />
                        <div class="col-span-2 flex items-center gap-1">
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-surface-sunken">
                                <div class="h-full rounded-full bg-accent" :style="{ width: `${splitPct(row.amount)}%` }" />
                            </div>
                            <span class="w-8 text-end text-xs tabular-nums text-muted">{{ splitPct(row.amount) }}%</span>
                        </div>
                        <VInput v-model="row.description" class="col-span-3" :placeholder="$t('expenses.split_desc')" />
                        <button type="button" class="col-span-1 rounded-sm p-1.5 text-muted hover:text-status-danger"
                            :disabled="splits.length <= 1" @click="removeSplitRow(i)">
                            <AppIcon name="trash" class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="flex items-center justify-between">
                        <VButton variant="ghost" size="sm" icon="plus" @click="addSplitRow">{{ $t('expenses.split_add') }}</VButton>
                        <span v-if="form.errors.splits" class="text-xs text-status-danger">{{ form.errors.splits }}</span>
                    </div>
                </div>

                <FormField k="expenses.file" :error="form.errors.file" class="sm:col-span-2">
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="block w-full text-sm text-ink-soft
                        file:me-3 file:rounded-md file:border-0 file:bg-surface-sunken file:px-3 file:py-1.5
                        file:text-sm file:text-ink hover:file:bg-surface-hover" @change="onFilePicked" />
                    <p class="mt-1 text-xs text-muted">{{ $t('expenses.file_hint') }}</p>
                    <p v-if="currentFile" class="mt-1 text-xs text-ink-soft">
                        {{ $t('expenses.current_file') }}: {{ currentFile }}
                    </p>
                </FormField>

                <FormField k="expenses.notes" class="sm:col-span-2">
                    <VTextarea v-model="form.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <span v-if="editingApproved" class="me-auto text-xs text-status-warn">
                    {{ $t('expenses.approved_locked') }}
                </span>
                <VButton variant="ghost" @click="showModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton v-if="!editingApproved" type="submit" form="expense-form" :loading="form.processing"
                    :disabled="splitMode && !splitBalanced">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <VehicleExpenseModal :open="showVehicleModal" :vehicles="vehicles" :vendors="vendors"
            :employees="employees" @close="showVehicleModal = false" />

        <VModal :open="showCategories" title-key="expenses.categories_title" @close="showCategories = false">
            <div class="space-y-4">
                <form class="flex items-end gap-2" @submit.prevent="addCategory">
                    <FormField k="expenses.category_name" :error="categoryForm.errors.name" class="flex-1">
                        <VInput v-model="categoryForm.name" />
                    </FormField>
                    <VButton type="submit" :loading="categoryForm.processing">
                        <Bilingual k="expenses.category_add" inline />
                    </VButton>
                </form>
                <ul class="divide-y divide-line rounded-lg border border-line">
                    <li v-for="c in categories" :key="c.id" class="flex items-center justify-between gap-2 px-3 py-2">
                        <span class="text-sm" :class="c.active ? 'text-ink' : 'text-muted line-through'">
                            {{ c.name }}
                            <VBadge v-if="c.company_id === null" status="neutral" class="ms-1">
                                <Bilingual k="expenses.category_default" inline />
                            </VBadge>
                        </span>
                        <!-- Group defaults (company_id null) are read-only for a company. -->
                        <span v-if="c.company_id !== null" class="flex items-center gap-1.5">
                            <VButton variant="ghost" size="sm" @click="toggleCategory(c)">
                                {{ c.active ? $t('expenses.category_inactive') : $t('expenses.category_active') }}
                            </VButton>
                            <VButton variant="ghost" size="sm" icon="trash" @click="deleteCategory(c)" />
                        </span>
                    </li>
                </ul>
            </div>
            <template #footer>
                <VButton variant="ghost" @click="showCategories = false"><Bilingual k="common.close" inline /></VButton>
            </template>
        </VModal>

        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
