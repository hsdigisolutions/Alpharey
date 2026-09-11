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
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
import { useFormDraft } from '@/composables/useFormDraft';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DraftBanner from '@/Components/ui/DraftBanner.vue';
import ExpenseReceiptDetail from '@/Components/Expenses/ExpenseReceiptDetail.vue';
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
    receipts: { type: Array, default: () => [] },
    receiptFilters: { type: Object, default: () => ({ from: null, to: null, scope: 'company', project_id: null }) },
    canScopeAll: { type: Boolean, default: false },
});

const filters = reactive({
    search: props.filters.search ?? '',
    project_id: props.filters.project_id ?? '',
    vendor_id: props.filters.vendor_id ?? '',
    type: props.filters.type ?? '',
    expense_category_id: props.filters.expense_category_id ?? '',
    payment_status: props.filters.payment_status ?? '',
    approval: props.filters.approval ?? '',
    taxable: props.filters.taxable ?? '',
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

/* ---------- Receipts / Documents tab ---------- */
const tab = ref('expenses'); // 'expenses' | 'receipts'

const rc = reactive({
    from: props.receiptFilters.from ?? '',
    to: props.receiptFilters.to ?? '',
    scope: props.receiptFilters.scope ?? 'company',
    project_id: props.receiptFilters.project_id ?? '',
});

// Date-range presets, mirroring the Reports filter bar.
const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const firstOfMonth = (o = 0) => isoDate(new Date(new Date().getFullYear(), new Date().getMonth() + o, 1));
const lastOfMonth = (o = 0) => isoDate(new Date(new Date().getFullYear(), new Date().getMonth() + o + 1, 0));
const startOfWeek = () => { const d = new Date(); const dow = (d.getDay() + 6) % 7; d.setDate(d.getDate() - dow); return isoDate(d); };
const endOfWeek = () => { const d = new Date(); const dow = (d.getDay() + 6) % 7; d.setDate(d.getDate() - dow + 6); return isoDate(d); };

function computeRcPreset() {
    if (!rc.from && !rc.to) return 'all';
    if (rc.from === startOfWeek() && rc.to === endOfWeek()) return 'week';
    if (rc.from === firstOfMonth(0) && rc.to === lastOfMonth(0)) return 'month';
    return 'custom';
}
const rcPreset = ref(computeRcPreset());
const rcPresets = ['all', 'week', 'month', 'custom'];
function setRcPreset(p) {
    rcPreset.value = p;
    if (p === 'all') { rc.from = ''; rc.to = ''; }
    else if (p === 'week') { rc.from = startOfWeek(); rc.to = endOfWeek(); }
    else if (p === 'month') { rc.from = firstOfMonth(0); rc.to = lastOfMonth(0); }
    if (p !== 'custom') applyReceipts();
}
function onRcCustom() { rcPreset.value = 'custom'; applyReceipts(); }

function applyReceipts() {
    router.get('/expenses', {
        rc_from: rc.from || undefined,
        rc_to: rc.to || undefined,
        rc_scope: rc.scope,
        rc_project: rc.scope === 'project' ? (rc.project_id || undefined) : undefined,
    }, {
        preserveScroll: true, preserveState: true, only: ['receipts', 'receiptFilters'],
        onSuccess: () => { tab.value = 'receipts'; },
    });
}

// Query string for the ZIP / combined-PDF export links (same rc_* filters).
const receiptExportQuery = computed(() => {
    const p = { from: rc.from || undefined, to: rc.to || undefined, scope: rc.scope };
    if (rc.scope === 'project' && rc.project_id) p.project_id = rc.project_id;
    return new URLSearchParams(Object.fromEntries(Object.entries(p).filter(([, v]) => v != null))).toString();
});

// Premium receipt detail (BUG 2) — opens in a wide modal, reused by the Recibos
// tab and the review queue (BUG 4). `detailRow` is the enriched receipt/expense
// row; `detailReviewable` when the SA can decide on an in-review row here.
const detailRow = ref(null);
function openDetail(r) { detailRow.value = r; }
function closeDetail() { detailRow.value = null; }
const detailReviewable = computed(() => !!(detailRow.value && props.can.approve_final && detailRow.value.review_status === 'in_review'));
function decideFromDetail(value) {
    if (!detailRow.value) return;
    approve(detailRow.value, value);
    closeDetail();
}

/* ---------- create / edit ---------- */
const showModal = ref(false);
const showVehicleModal = ref(false);
const editingId = ref(null);
const editingApproved = ref(false); // approved expenses are view-only (locked server-side)
const blank = {
    number: '', type: 'factura', expense_category_id: '', vendor_id: '', project_id: '',
    employee_id: '', company_card_id: '', date: null, due_date: null,
    subtotal: 0, is_taxable: true, vat_rate: null, vat_custom_percent: null, payment_method: '', payment_status: 'unpaid',
    bearable_by: 'company', deduct_from_salary: false, notes: '', file: null,
};
const form = useForm({ ...blank });
const currentFile = ref(null);
const draft = useFormDraft(form, { key: () => `expense:${editingId.value ?? 'new'}` });
watch(showModal, (v) => { if (!v) draft.disarm(); });

// Non-taxable (no sujeta / exenta) operations normally carry no VAT — clear the
// rate as a SOFT nudge when the user marks the expense non-taxable. Fully
// overridable (they can re-pick a rate); VAT math itself is never touched.
watch(() => form.is_taxable, (taxable) => {
    if (!taxable) { form.vat_rate = null; form.vat_custom_percent = null; }
});

// On THIS tab, an Employee-borne expense means "deduct from salary" (worker
// reimbursements go through the Worker Expense tab). Default the toggle on when
// Employee is picked, and clear it otherwise — the admin can still override.
watch(() => form.bearable_by, (bearer) => {
    form.deduct_from_salary = bearer === 'employee';
});

// The specific company card only applies to the Company-card method — clear it
// when the method changes so a stale card id is never saved against Cash etc.
watch(() => form.payment_method, (method) => {
    if (method !== 'company_card') {
        form.company_card_id = '';
    }
});

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
    draft.arm();
}

// The cross-charge engine's internal_deployment Gasto is a system record — it
// is read-only server-side (never editable/deletable/approvable) and must be
// read-only here too. Clicking it opens a WHY-is-this-here detail panel (days,
// period, project, live/locked) with the worker deliberately anonymised.
function isDeployment(row) {
    return row.type === 'internal_deployment';
}

const deploymentDetail = ref(null);      // fetched payload
const deploymentLoading = ref(false);
const deploymentError = ref(false);
const showDeploymentDetail = ref(false);

function openDeploymentDetail(row) {
    deploymentDetail.value = null;
    deploymentError.value = false;
    deploymentLoading.value = true;
    showDeploymentDetail.value = true;
    fetch(`/expenses/${row.id}/deployment-detail`, { headers: { Accept: 'application/json' } })
        .then((res) => {
            if (!res.ok) {
                throw new Error('detail_failed');
            }
            return res.json();
        })
        .then((data) => { deploymentDetail.value = data; })
        .catch(() => { deploymentError.value = true; })
        .finally(() => { deploymentLoading.value = false; });
}

function openEdit(row) {
    if (isDeployment(row)) {
        openDeploymentDetail(row); // read-only system record → detail panel
        return;
    }
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
        is_taxable: row.is_taxable ?? true,
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
    draft.arm();
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
    const opts = { preserveScroll: true, onSuccess: () => { draft.clear(); showModal.value = false; } };
    editingId.value ? payload.post(`/expenses/${editingId.value}`, opts) : payload.post('/expenses', opts);
}

function approve(row, value) {
    router.post(`/expenses/${row.id}/approve`, { approved: value }, { preserveScroll: true });
}

// Send to the Super-Admin review queue with an optional note (BUG 4).
const reviewNoteTarget = ref(null);
const reviewNoteForm = useForm({ review_note: '' });
function openSendToReview(row) {
    reviewNoteTarget.value = row;
    reviewNoteForm.reset();
}
function submitSendToReview() {
    reviewNoteForm.post(`/expenses/${reviewNoteTarget.value.id}/review`, {
        preserveScroll: true,
        onSuccess: () => { reviewNoteTarget.value = null; reviewNoteForm.reset(); },
    });
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

        <!-- Gastos / Recibos tab switch -->
        <div class="mb-4 flex gap-1 border-b border-line">
            <button type="button" class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition"
                :class="tab === 'expenses' ? 'border-accent text-ink' : 'border-transparent text-ink-soft hover:text-ink'"
                @click="tab = 'expenses'">{{ $t('expenses.tab_list') }}</button>
            <button type="button" class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition"
                :class="tab === 'receipts' ? 'border-accent text-ink' : 'border-transparent text-ink-soft hover:text-ink'"
                @click="tab = 'receipts'">{{ $t('expenses.tab_receipts') }} <span class="text-xs text-muted">({{ receipts.length }})</span></button>
        </div>

        <!-- ============ GASTOS (expense list) ============ -->
        <div v-show="tab === 'expenses'">
        <!-- Summary cards — count + € per approval state. Clickable. -->
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <VKpiCard k="stats.total" :value="stats.total.count" :sub="eur(stats.total.amount)" clickable :active="filters.approval === ''" @click="setApproval('')" />
            <VKpiCard k="stats.approved" :value="stats.approved.count" :sub="eur(stats.approved.amount)" status="ok" clickable :active="filters.approval === 'approved'" @click="setApproval('approved')" />
            <VKpiCard k="stats.pending" :value="stats.pending.count" :sub="eur(stats.pending.amount)" status="warn" clickable :active="filters.approval === 'pending'" @click="setApproval('pending')" />
            <VKpiCard v-if="stats.in_review" k="stats.in_review" :value="stats.in_review.count" :sub="eur(stats.in_review.amount)" status="info" clickable :active="filters.approval === 'in_review'" @click="setApproval('in_review')" />
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
            <VSelect v-model="filters.taxable" @update:model-value="apply()">
                <option value="">{{ $t('expenses.taxable') }}</option>
                <option value="taxable">{{ $t('expenses.taxable_yes') }}</option>
                <option value="non_taxable">{{ $t('expenses.taxable_no') }}</option>
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
                    <VBadge v-if="isDeployment(r)" status="info" class="ms-1" :title="$t('expenses.deployment_badge_hint')">
                        {{ $t('expenses.deployment_badge') }}
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
                        <button v-if="can.edit && !r.approved && !isDeployment(r)" type="button"
                            class="rounded-sm p-1.5 text-muted hover:text-ink" :title="$t('expenses.edit')"
                            @click="openEdit(r)">
                            <AppIcon name="edit" class="h-3.5 w-3.5" />
                        </button>
                        <template v-if="can.approve_final && r.review_status !== 'in_review' && !isDeployment(r)">
                            <VButton v-if="!r.approved" variant="ghost" size="sm" @click="approve(r, true)">
                                <Bilingual k="expenses.approve" inline />
                            </VButton>
                            <VButton v-else variant="ghost" size="sm" @click="approve(r, false)">
                                <Bilingual k="expenses.reject" inline />
                            </VButton>
                            <VButton v-if="!r.approved" variant="ghost" size="sm" @click="openSendToReview(r)">
                                <Bilingual k="expenses.send_to_review" inline />
                            </VButton>
                        </template>
                        <!-- In-review row (BUG 4): the final approver opens the full-detail
                             review panel and decides there; others just view it. -->
                        <VButton v-if="r.review_status === 'in_review'" variant="ghost" size="sm" icon="eye" @click="openDetail(r)">
                            <Bilingual :k="can.approve_final ? 'expenses.review' : 'expenses.view'" inline />
                        </VButton>
                        <VButton v-if="can.delete && !r.approved && !isDeployment(r)" variant="ghost" size="sm" icon="trash" @click="destroy(r)" />
                    </span>
                </td>
            </tr>
            <template v-if="expenses.data.length === 0" #empty>
                <VEmptyState icon="expenses" message-key="expenses.no_rows" />
            </template>
        </VTable>

        <VPagination :page="expenses.current_page" :pages="expenses.last_page" :total="expenses.total"
            @update:page="(p) => apply({ page: p })" />
        </div>

        <!-- ============ RECIBOS (receipt documents for tax filing) ============ -->
        <div v-show="tab === 'receipts'">
            <!-- Filter bar: date range presets + scope -->
            <div class="mb-3 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface-raised p-3">
                <div>
                    <p class="mb-1 text-xs font-medium text-ink-soft">{{ $t('expenses.rc_period') }}</p>
                    <div class="flex overflow-hidden rounded-md border border-line-strong text-xs font-medium">
                        <button v-for="p in rcPresets" :key="p" type="button" class="px-2.5 py-1.5 transition"
                            :class="rcPreset === p ? 'bg-accent text-on-accent' : 'text-ink-soft hover:bg-surface-hover'"
                            @click="setRcPreset(p)">{{ $t(`expenses.rc_range_${p}`) }}</button>
                    </div>
                </div>
                <template v-if="rcPreset === 'custom'">
                    <FormField k="expenses.rc_from"><VDateInput v-model="rc.from" @update:model-value="onRcCustom()" /></FormField>
                    <FormField k="expenses.rc_to"><VDateInput v-model="rc.to" @update:model-value="onRcCustom()" /></FormField>
                </template>
                <FormField k="expenses.rc_scope">
                    <VSelect v-model="rc.scope" @update:model-value="applyReceipts()">
                        <option value="company">{{ $t('expenses.rc_scope_company') }}</option>
                        <option value="project">{{ $t('expenses.rc_scope_project') }}</option>
                        <option v-if="canScopeAll" value="all">{{ $t('expenses.rc_scope_all') }}</option>
                    </VSelect>
                </FormField>
                <FormField v-if="rc.scope === 'project'" k="expenses.project">
                    <VSelect v-model="rc.project_id" @update:model-value="applyReceipts()">
                        <option value="">{{ $t('expenses.project') }}</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
                <div class="ms-auto flex items-end gap-2">
                    <a v-if="can.export && receipts.length" :href="`/expenses/receipts/export/zip?${receiptExportQuery}`"
                        class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3 py-1.5 text-sm font-medium text-on-accent hover:bg-accent-hover">
                        <AppIcon name="download" class="h-4 w-4" /> {{ $t('expenses.rc_export_zip') }}
                    </a>
                    <a v-if="can.export && receipts.length" :href="`/expenses/receipts/export/pdf?${receiptExportQuery}`"
                        class="inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-surface-raised px-3 py-1.5 text-sm text-ink hover:bg-surface-hover">
                        <AppIcon name="download" class="h-4 w-4" /> {{ $t('expenses.rc_export_pdf') }}
                    </a>
                </div>
            </div>

            <VEmptyState v-if="!receipts.length" icon="file" :title="$t('expenses.rc_empty')" />
            <div v-else class="overflow-hidden rounded-lg border border-line">
                <table class="w-full text-sm">
                    <thead class="bg-surface-sunken text-xs uppercase text-muted">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ $t('expenses.rc_date') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('expenses.vendor') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('worker_expenses.employee') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('expenses.total') }}</th>
                            <th class="px-3 py-2 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in receipts" :key="r.id"
                            class="cursor-pointer border-t border-line hover:bg-surface-hover"
                            @click="openDetail(r)">
                            <td class="tabular-nums px-3 py-2.5">{{ r.date }}</td>
                            <td class="px-3 py-2.5">
                                <span class="font-medium text-ink">{{ r.vendor ?? r.category ?? '—' }}</span>
                                <span class="block text-xs text-muted">{{ r.category ?? '—' }}<template v-if="r.number"> · {{ r.number }}</template></span>
                            </td>
                            <td class="px-3 py-2.5 text-ink-soft">{{ r.employee ?? '—' }}</td>
                            <td class="tabular-nums px-3 py-2.5 text-end">{{ eur(r.total) }}</td>
                            <td class="px-3 py-2.5 text-end">
                                <VBadge status="neutral">{{ r.ext || '?' }}</VBadge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <VModal :open="showModal" size="lg" :title-key="editingId ? 'expenses.edit' : 'expenses.new'" @close="showModal = false">
            <form id="expense-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <DraftBanner class="sm:col-span-2" :show="draft.hasDraft.value" @discard="draft.discard()" />
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
                <FormField k="expenses.taxable" :error="form.errors.is_taxable">
                    <div class="flex overflow-hidden rounded-md border border-line-strong bg-surface-sunken text-sm font-medium">
                        <button type="button" class="flex-1 px-3 py-2 transition"
                            :class="form.is_taxable ? 'bg-accent text-on-accent' : 'text-ink-soft hover:bg-surface-hover'"
                            @click="form.is_taxable = true">{{ $t('expenses.taxable_yes') }}</button>
                        <button type="button" class="flex-1 border-s border-line-strong px-3 py-2 transition"
                            :class="!form.is_taxable ? 'bg-accent text-on-accent' : 'text-ink-soft hover:bg-surface-hover'"
                            @click="form.is_taxable = false">{{ $t('expenses.taxable_no') }}</button>
                    </div>
                </FormField>
                <FormField k="expenses.vat" :error="form.errors.vat_rate || form.errors.vat_custom_percent">
                    <VVatSelect v-model="form.vat_rate" v-model:custom-percent="form.vat_custom_percent" :options="vatOptions" />
                    <p v-if="!form.is_taxable" class="mt-1 text-xs text-muted">{{ $t('expenses.taxable_no_hint') }}</p>
                </FormField>

                <FormField k="expenses.payment_method" :error="form.errors.payment_method">
                    <VSelect v-model="form.payment_method">
                        <option value="">—</option>
                        <option v-for="m in paymentMethods" :key="m" :value="m">
                            {{ $t(`expenses.pm_${m}`) }}
                        </option>
                    </VSelect>
                </FormField>
                <!-- Which company card was used — only relevant when the method is Company card. -->
                <FormField v-if="form.payment_method === 'company_card'" k="expenses.card" :error="form.errors.company_card_id">
                    <VSelect v-model="form.company_card_id">
                        <option value="">—</option>
                        <option v-for="c in cards" :key="c.id" :value="c.id">
                            {{ c.label }}{{ c.last_four ? ` ••${c.last_four}` : '' }}
                        </option>
                    </VSelect>
                    <p v-if="cards.length === 0" class="mt-1 text-xs text-muted">{{ $t('expenses.no_cards_hint') }}</p>
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
                        <span class="text-sm">{{ $t('expenses.deduct_from_salary_label') }}</span>
                    </VCheckbox>
                </label>
                <!-- Steer worker reimbursements to the Worker Expense tab (this
                     tab's Employee option is for DEDUCTIONS, not reimbursements). -->
                <p class="sm:col-span-2 -mt-2 rounded-md bg-status-info-soft px-3 py-2 text-xs text-status-info">
                    {{ $t('expenses.bearable_reimburse_hint') }}
                </p>
                <p class="sm:col-span-2 text-xs text-muted">{{ $t('expenses.bearable_hint') }}</p>

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

        <!-- Item B — read-only host detail for a deployment cross-charge. Explains
             WHY the expense exists; the deployed worker is never named. -->
        <VModal :open="showDeploymentDetail" size="md" title-key="expenses.deployment_detail_title" @close="showDeploymentDetail = false">
            <div v-if="deploymentLoading" class="py-8 text-center text-sm text-muted">{{ $t('common.loading') }}</div>
            <div v-else-if="deploymentError" class="py-8 text-center text-sm text-status-danger">{{ $t('expenses.deployment_detail_error') }}</div>
            <div v-else-if="deploymentDetail" class="space-y-4">
                <p class="text-sm text-ink-soft">{{ $t('expenses.deployment_detail_intro') }}</p>

                <!-- amount hero + live/locked status -->
                <div class="rounded-lg border border-line bg-surface-sunken p-4">
                    <div class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('expenses.deployment_amount') }}</div>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="text-2xl font-semibold tabular-nums text-ink">{{ eur(deploymentDetail.amount) }}</span>
                        <VBadge :status="deploymentDetail.status === 'locked' ? 'neutral' : 'info'">
                            {{ deploymentDetail.status === 'locked' ? $t('expenses.deployment_locked') : $t('expenses.deployment_live') }}
                        </VBadge>
                    </div>
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div class="col-span-2">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('expenses.deployment_project') }}</dt>
                        <dd class="mt-0.5 font-medium text-ink">{{ deploymentDetail.project ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('expenses.deployment_days') }}</dt>
                        <dd class="mt-0.5 font-medium tabular-nums text-ink">{{ deploymentDetail.days }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('expenses.deployment_units') }}</dt>
                        <dd class="mt-0.5 font-medium tabular-nums text-ink">
                            {{ deploymentDetail.units }} {{ $t(`expenses.deployment_unit_${deploymentDetail.rate_type}`) }}
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('expenses.deployment_period') }}</dt>
                        <dd class="mt-0.5 font-medium tabular-nums text-ink">
                            {{ deploymentDetail.period_start }} → {{ deploymentDetail.period_end ?? $t('expenses.deployment_ongoing') }}
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('expenses.deployment_from') }}</dt>
                        <dd class="mt-0.5 font-medium text-ink">{{ deploymentDetail.home_company ?? '—' }}</dd>
                    </div>
                </dl>

                <p class="rounded-md bg-surface-sunken px-3 py-2 text-xs text-muted">{{ $t('expenses.deployment_worker_hidden') }}</p>
            </div>
        </VModal>

        <!-- Premium receipt detail (BUG 2) + Super-Admin review decision (BUG 4). -->
        <VModal :open="!!detailRow" size="xl" title-key="expenses.receipt_detail_title" @close="closeDetail">
            <ExpenseReceiptDetail v-if="detailRow" :row="detailRow" />
            <template v-if="detailReviewable" #footer>
                <VButton variant="ghost" class="text-status-danger" @click="decideFromDetail(false)">
                    <Bilingual k="expenses.reject" inline />
                </VButton>
                <VButton @click="decideFromDetail(true)">
                    <Bilingual k="expenses.approve" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Send to review — optional note for the Super Admin (BUG 4). -->
        <VModal :open="!!reviewNoteTarget" size="sm" title-key="expenses.send_to_review" @close="reviewNoteTarget = null">
            <form id="review-note-form" @submit.prevent="submitSendToReview">
                <p class="mb-2 text-xs text-ink-soft"><Bilingual k="expenses.review_note_hint" /></p>
                <VTextarea v-model="reviewNoteForm.review_note" :rows="3" :placeholder="$t('expenses.review_note_placeholder')" />
            </form>
            <template #footer>
                <VButton variant="ghost" @click="reviewNoteTarget = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="review-note-form" :loading="reviewNoteForm.processing">
                    <Bilingual k="expenses.send_to_review" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
