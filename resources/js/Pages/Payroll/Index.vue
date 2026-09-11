<script setup>
/**
 * Screen 12 — Payroll. Calculate → review breakdown → adjust → Approve All →
 * mark paid → Lock Period.
 *
 * Every money figure arrives already gated on `payroll.view` server-side and is
 * null otherwise — this page never decides who may see pay, it just renders
 * what it was given.
 */
import { computed, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    month: { type: String, required: true },
    rows: { type: Array, required: true },
    summary: { type: Object, required: true },
    advances: { type: Array, default: () => [] },
    locked: { type: Boolean, default: false },
    can: { type: Object, required: true },
});

const page = usePage();

function go(month) {
    router.get('/payroll', { month }, { preserveScroll: true, preserveState: true });
}

function changeMonth(delta) {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    go(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
}

const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Intl.DateTimeFormat(page.props.locale.primary === 'es' ? 'es-ES' : 'en-GB',
        { month: 'long', year: 'numeric' }).format(new Date(y, m - 1, 1));
});

const post = (url) => router.post(url, { month: props.month }, { preserveScroll: true });

// --- Bulk selection + actions (Feature 5) ---
const selectedIds = ref([]);
watch(() => props.month, () => { selectedIds.value = []; }); // reset when the month changes
const isSelected = (id) => selectedIds.value.includes(id);
function toggleRow(id) {
    selectedIds.value = isSelected(id) ? selectedIds.value.filter((x) => x !== id) : [...selectedIds.value, id];
}
const allSelected = computed(() => props.rows.length > 0 && selectedIds.value.length === props.rows.length);
function toggleAll(checked) {
    selectedIds.value = checked ? props.rows.map((r) => r.id) : [];
}
const canSelect = computed(() => !props.locked && (props.can.approve || props.can.edit || props.can.export));
const showPaidConfirm = ref(false);
function bulkAction(url) {
    router.post(url, { ids: selectedIds.value }, { preserveScroll: true, onSuccess: () => { selectedIds.value = []; } });
}
function bulkApprove() { bulkAction('/payroll/bulk-approve'); }
function confirmBulkPaid() { showPaidConfirm.value = false; bulkAction('/payroll/bulk-paid'); }
function bulkExport(format) {
    const params = new URLSearchParams();
    selectedIds.value.forEach((id) => params.append('ids[]', id));
    params.append('format', format);
    window.location.href = `/payroll/bulk-export?${params.toString()}`;
}

function markPaid(row) {
    router.post(`/payroll/${row.id}/paid`, { payment_method: row.payment_method }, { preserveScroll: true });
}

// Synchronous per-employee recalculation — re-reads attendance/rate/expenses/
// fines/advances now and refreshes this row's figures immediately.
function recalc(row) {
    router.post(`/payroll/${row.id}/recalculate`, {}, { preserveScroll: true });
}

/* ---------- breakdown ----------
 * Track the row by id and read it back out of props, never by holding the row
 * object: after any action Inertia hands us a NEW rows array, and a captured
 * object would leave the modal showing pre-adjustment figures. On a payroll
 * screen a stale net is worse than no net.
 */
const breakdownId = ref(null);
const breakdown = computed(() => props.rows.find((r) => r.id === breakdownId.value) ?? null);

// Whole numbers show plain (15 días); fractional show 2 decimals (7,5 h).
function dtNum(n) {
    const f = Number(n);
    return f === Math.trunc(f) ? String(Math.trunc(f))
        : f.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Hours as "28h 57m" rather than the raw decimal 28.95.
function hoursHM(h) {
    const hh = Math.floor(Math.max(0, Number(h) || 0));
    const mm = Math.round((Math.max(0, Number(h) || 0) - hh) * 60);
    return `${hh}h ${String(mm).padStart(2, '0')}m`;
}

/* ---------- new / edit advance ---------- */
const advanceOpen = ref(false);
const editingAdvanceId = ref(null);
const advanceForm = useForm({
    employee_id: '', amount: null, reason: '',
    request_date: props.month + '-01', payroll_month: props.month,
    payment_method: 'cash', receipt: null,
    // Added from Payroll = approve + deduct this month immediately.
    approve: true,
});
function openAdvance() {
    editingAdvanceId.value = null;
    advanceForm.reset();
    advanceForm.request_date = props.month + '-01';
    advanceForm.payroll_month = props.month;
    advanceForm.payment_method = 'cash';
    advanceOpen.value = true;
}
function openEditAdvance(a) {
    editingAdvanceId.value = a.id;
    advanceForm.reset();
    advanceForm.employee_id = a.employee_id;
    advanceForm.amount = a.amount;
    advanceForm.reason = a.reason ?? '';
    advanceForm.request_date = a.request_date;
    advanceForm.payroll_month = a.payroll_month ?? props.month;
    advanceForm.payment_method = a.payment_method ?? 'cash';
    advanceForm.receipt = null;
    advanceOpen.value = true;
}
function pickReceipt(e) {
    advanceForm.receipt = e.target.files?.[0] ?? null;
}
function submitAdvance() {
    const url = editingAdvanceId.value ? `/advances/${editingAdvanceId.value}` : '/advances';
    advanceForm.transform((d) => ({ ...d, reason: d.reason || null }))
        .post(url, {
            forceFormData: true, // a bank-transfer receipt file may be attached
            preserveScroll: true,
            onSuccess: () => { advanceOpen.value = false; advanceForm.reset(); editingAdvanceId.value = null; },
        });
}
function deleteAdvance(a) {
    if (window.confirm(t('advances.delete_confirm'))) {
        router.delete(`/advances/${a.id}`, { preserveScroll: true });
    }
}
function advStatus(s) {
    return { pending: 'warn', approved: 'ok', rejected: 'danger', deducted: 'info' }[s] ?? 'neutral';
}

/* ---------- manual adjustments ---------- */
const adjustingId = ref(null);
const adjusting = computed(() => props.rows.find((r) => r.id === adjustingId.value) ?? null);
const adjustForm = useForm({ other_deductions: 0, manual_additions: 0, notes: '' });

function openAdjust(row) {
    adjustingId.value = row.id;
    adjustForm.other_deductions = row.other_deductions ?? 0;
    adjustForm.manual_additions = row.manual_additions ?? 0;
    adjustForm.notes = row.notes ?? '';
    adjustForm.clearErrors();
}

function submitAdjust() {
    adjustForm.put(`/payroll/${adjustingId.value}/adjust`, {
        preserveScroll: true,
        onSuccess: () => (adjustingId.value = null),
    });
}

function eur(n) {
    if (n === null || n === undefined) return '—';
    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(n));
}

/* Y-m-d → dd/mm (the rate-period lines; the month is obvious from context). */
function shortDate(d) {
    if (!d) return '';
    const [, m, day] = String(d).split('-');
    return `${day}/${m}`;
}

const columns = [
    { key: 'employee', labelKey: 'payroll.employee' },
    { key: 'days', labelKey: 'payroll.days', align: 'end' },
    { key: 'hours', labelKey: 'payroll.hours', align: 'end' },
    { key: 'wage_type', labelKey: 'payroll.wage_type' },
    { key: 'wage_rate', labelKey: 'payroll.wage_rate', align: 'end' },
    { key: 'gross', labelKey: 'payroll.gross', align: 'end' },
    { key: 'deductions', labelKey: 'payroll.advance_deductions', align: 'end' },
    { key: 'net', labelKey: 'payroll.net', align: 'end' },
    { key: 'status', labelKey: 'payroll.status' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
</script>

<template>
    <Head :title="$t('payroll.title')" />
    <AppLayout>
        <VPageHeader k="payroll.title">
            <VButton v-if="can.create && !locked" variant="secondary" icon="plus" @click="post('/payroll/calculate')">
                <Bilingual k="payroll.calculate" inline />
            </VButton>
            <VButton v-if="can.create && !locked" variant="secondary" icon="plus" @click="openAdvance()">
                <Bilingual k="payroll.new_advance" inline />
            </VButton>
            <VButton v-if="can.approve && !locked" variant="secondary" icon="check" @click="post('/payroll/approve-all')">
                <Bilingual k="payroll.approve_all" inline />
            </VButton>
            <VButton v-if="can.approve && !locked" variant="secondary" @click="post('/payroll/lock')">
                <Bilingual k="payroll.lock" inline />
            </VButton>
            <VButton v-if="can.approve && locked" variant="secondary" @click="post('/payroll/unlock')">
                <Bilingual k="payroll.unlock" inline />
            </VButton>
            <a v-if="can.export" :href="`/payroll/export?month=${month}`"
                class="inline-flex items-center gap-2 rounded-md border border-line bg-surface-raised px-3.5 py-2 text-sm font-medium text-ink hover:bg-surface-hover">
                <AppIcon name="export" class="h-4 w-4" /> Excel
            </a>
            <a v-if="can.download" :href="`/payroll/payslips?month=${month}`"
                class="inline-flex items-center gap-2 rounded-md border border-line bg-surface-raised px-3.5 py-2 text-sm font-medium text-ink hover:bg-surface-hover">
                <AppIcon name="download" class="h-4 w-4" /> PDF
            </a>
        </VPageHeader>

        <!-- Month navigation + lock state -->
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="changeMonth(-1)">
                <AppIcon name="chevron-left" class="h-4 w-4" />
            </button>
            <span class="min-w-40 text-center text-sm font-semibold capitalize">{{ monthLabel }}</span>
            <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="changeMonth(1)">
                <AppIcon name="chevron-right" class="h-4 w-4" />
            </button>
            <VBadge v-if="locked" status="neutral"><Bilingual k="payroll.is_locked" inline /></VBadge>
        </div>

        <!-- Summary -->
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-lg border border-line bg-surface-raised p-3 shadow-card">
                <Bilingual k="payroll.summary_employees" class="text-xs text-ink-soft" />
                <p class="tabular-nums mt-1 text-xl font-semibold">{{ summary.employees }}</p>
            </div>
            <div class="rounded-lg border border-line bg-surface-raised p-3 shadow-card">
                <Bilingual k="payroll.summary_pending" class="text-xs text-ink-soft" />
                <p class="tabular-nums mt-1 text-xl font-semibold text-status-warn">{{ summary.pending }}</p>
            </div>
            <div class="rounded-lg border border-line bg-surface-raised p-3 shadow-card">
                <Bilingual k="payroll.summary_paid" class="text-xs text-ink-soft" />
                <p class="tabular-nums mt-1 text-xl font-semibold text-status-ok">{{ summary.paid }}</p>
            </div>
            <div class="rounded-lg border border-line bg-surface-raised p-3 shadow-card">
                <Bilingual k="payroll.summary_net" class="text-xs text-ink-soft" />
                <p class="tabular-nums mt-1 text-xl font-semibold">{{ eur(summary.net_total) }}</p>
            </div>
        </div>

        <VTable :columns="columns" :selectable="canSelect" :all-selected="allSelected" @toggle-all="toggleAll">
            <tr v-for="r in rows" :key="r.id" class="hover:bg-surface-hover"
                :class="isSelected(r.id) ? 'bg-accent-soft' : ''">
                <td v-if="canSelect" class="px-3 py-2.5">
                    <input type="checkbox" :checked="isSelected(r.id)" class="h-4 w-4 rounded-sm accent-[var(--color-accent)]"
                        :aria-label="`${r.employee}`" @change="toggleRow(r.id)" />
                </td>
                <td class="px-3 py-2.5 text-sm">
                    <span class="block font-medium text-ink">{{ r.employee }}</span>
                    <span class="block text-xs text-muted">{{ r.designation ?? '—' }}</span>
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ r.attendance_days }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ hoursHM(r.attendance_hours) }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">
                    <Bilingual v-if="r.wage_type" :k="`employees.wage_${r.wage_type}`" inline />
                    <span v-else>—</span>
                </td>
                <!-- Tarifa: the rate matching the worker's own wage type (server-side match). -->
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ eur(r.wage_rate) }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ eur(r.gross_pay) }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm text-status-danger">
                    {{ r.advance_deductions ? `− ${eur(r.advance_deductions)}` : '—' }}
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm font-semibold">{{ eur(r.net_amount) }}</td>
                <td class="px-3 py-2.5">
                    <VBadge :status="r.status === 'paid' ? 'ok' : (r.approved ? 'info' : 'warn')">
                        <Bilingual :k="r.status === 'paid' ? 'payroll.status_paid' : (r.approved ? 'payroll.status_approved' : 'payroll.status_pending')" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5 text-end">
                    <span class="flex items-center justify-end gap-1.5">
                        <VButton variant="ghost" size="sm" icon="eye" @click="breakdownId = r.id">
                            <Bilingual k="payroll.breakdown" inline />
                        </VButton>
                        <VButton v-if="can.create && r.status !== 'paid' && !locked" variant="ghost" size="sm" icon="refresh"
                            @click="recalc(r)">
                            <Bilingual k="payroll.recalculate" inline />
                        </VButton>
                        <VButton v-if="can.edit && r.status !== 'paid' && !locked" variant="ghost" size="sm" icon="edit"
                            @click="openAdjust(r)" />
                        <VButton v-if="can.edit && r.status !== 'paid' && !locked" variant="ghost" size="sm"
                            @click="markPaid(r)">
                            <Bilingual k="payroll.mark_paid" inline />
                        </VButton>
                        <a v-if="can.download" :href="`/payroll/${r.id}/payslip`"
                            class="rounded-sm p-1.5 text-muted hover:text-ink" :title="'PDF'">
                            <AppIcon name="download" class="h-3.5 w-3.5" />
                        </a>
                    </span>
                </td>
            </tr>
            <template v-if="rows.length === 0" #empty>
                <VEmptyState icon="payroll" message-key="payroll.no_rows" />
            </template>
        </VTable>

        <!-- Bulk action bar (Feature 5) — appears when rows are selected -->
        <div v-if="selectedIds.length > 0"
            class="sticky bottom-4 z-10 mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-line-strong bg-surface-raised px-4 py-3 shadow-overlay">
            <span class="text-sm font-semibold text-ink">
                {{ selectedIds.length }} <Bilingual k="payroll.bulk_selected" inline />
            </span>
            <div class="flex flex-wrap items-center gap-2">
                <VButton v-if="can.approve" variant="secondary" size="sm" icon="check" @click="bulkApprove">
                    <Bilingual k="payroll.bulk_approve" inline />
                </VButton>
                <VButton v-if="can.edit" variant="secondary" size="sm" @click="showPaidConfirm = true">
                    <Bilingual k="payroll.bulk_mark_paid" inline />
                </VButton>
                <VButton v-if="can.export" variant="secondary" size="sm" icon="download" @click="bulkExport('excel')">
                    Excel
                </VButton>
                <VButton v-if="can.export && can.download" variant="secondary" size="sm" icon="download" @click="bulkExport('pdf')">
                    PDF
                </VButton>
            </div>
            <button type="button" class="ms-auto text-xs text-ink-soft hover:text-ink" @click="selectedIds = []">
                <Bilingual k="common.cancel" inline />
            </button>
        </div>

        <!-- Advances this month (Issue 1) — the editable list -->
        <section v-if="advances.length" class="mt-8">
            <h2 class="mb-2 text-section font-semibold text-ink"><Bilingual k="advances.title" inline /></h2>
            <div class="overflow-x-auto rounded-lg border border-line">
                <table class="w-full text-sm">
                    <thead class="bg-surface-sunken text-xs uppercase text-muted">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ $t('payroll.advance_employee') }}</th>
                            <th class="px-3 py-2 text-end">{{ $t('payroll.advance_amount') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('advances.payment_method') }}</th>
                            <th class="px-3 py-2 text-start">{{ $t('advances.status_col') }}</th>
                            <th class="px-3 py-2 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in advances" :key="a.id" class="border-t border-line bg-surface-raised">
                            <td class="px-3 py-2.5 text-ink">{{ a.employee }}</td>
                            <td class="px-3 py-2.5 text-end tabular-nums font-medium text-ink">{{ eur(a.amount) }}</td>
                            <td class="px-3 py-2.5 text-ink-soft">
                                <span v-if="a.payment_method">{{ $t('advances.pm_' + a.payment_method) }}</span>
                                <span v-else class="text-muted">—</span>
                                <a v-if="a.has_receipt" :href="`/advances/${a.id}/receipt`" target="_blank" rel="noopener"
                                    class="ms-2 text-xs text-accent hover:underline">{{ $t('advances.receipt') }}</a>
                            </td>
                            <td class="px-3 py-2.5">
                                <VBadge :status="advStatus(a.status)"><Bilingual :k="'advances.status_' + a.status" inline /></VBadge>
                            </td>
                            <td class="px-3 py-2.5 text-end">
                                <span class="flex items-center justify-end gap-1">
                                    <VButton v-if="can.edit && a.editable" variant="ghost" size="sm" icon="edit"
                                        :title="$t('advances.edit')" @click="openEditAdvance(a)" />
                                    <VButton v-if="can.edit && a.editable" variant="ghost" size="sm" icon="trash"
                                        :title="$t('common.delete')" @click="deleteAdvance(a)" />
                                    <span v-if="!a.editable" class="text-xs text-muted"><Bilingual k="advances.settled" inline /></span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Bulk mark-paid confirmation -->
        <VModal :open="showPaidConfirm" title-key="payroll.bulk_mark_paid" size="sm" @close="showPaidConfirm = false">
            <p class="text-sm text-ink-soft"><Bilingual k="payroll.bulk_paid_confirm" /></p>
            <template #footer>
                <VButton variant="ghost" @click="showPaidConfirm = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton variant="primary" @click="confirmBulkPaid"><Bilingual k="payroll.bulk_mark_paid" inline /></VButton>
            </template>
        </VModal>

        <!-- Breakdown modal — layout per REQUIREMENTS.md Screen 12 -->
        <VModal :open="breakdown !== null" title-key="payroll.breakdown" @close="breakdownId = null">
            <div v-if="breakdown" class="space-y-4">
                <p class="text-sm font-semibold">{{ breakdown.employee }}</p>

                <div v-if="breakdown.deployment_notes?.length" class="space-y-1">
                    <p v-for="(note, i) in breakdown.deployment_notes" :key="i"
                        class="rounded-md bg-status-info-soft px-3 py-2 text-xs text-status-info">{{ note }}</p>
                </div>

                <!-- Mid-month rate changes: the same per-period lines the payslip
                     PDF prints (only present when the rate actually changed). -->
                <div v-if="breakdown.rate_periods?.length" class="rounded-md border border-line bg-surface-sunken/50 px-3 py-2">
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted">
                        {{ $t('payroll.rate_periods') }}
                    </p>
                    <div v-for="(p, i) in breakdown.rate_periods" :key="i"
                        class="tabular-nums flex justify-between gap-4 text-sm">
                        <span class="text-ink-soft">
                            {{ $t('payroll.period') }} {{ i + 1 }}: {{ shortDate(p.from) }} → {{ shortDate(p.to) }}
                            <span class="text-muted">({{ eur(p.rate) }}{{ p.wage_type === 'daily' ? '/día' : '/h' }} · {{ p.days }} {{ $t('attendance.unit_days') }})</span>
                        </span>
                        <span>{{ eur(p.amount) }}</span>
                    </div>
                </div>

                <dl class="tabular-nums space-y-1.5 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt><Bilingual k="payroll.base_salary" inline /></dt>
                        <dd>{{ eur(breakdown.base_salary) }}</dd>
                    </div>
                    <!-- Per-day-type breakdown when present; else the generic days/hours lines -->
                    <template v-if="breakdown.day_type_summary?.length">
                        <div v-for="(s, i) in breakdown.day_type_summary" :key="i" class="flex justify-between gap-4">
                            <dt>
                                {{ s.weekend ? $t('attendance.weekend_days') : $t(`attendance.day_type_${s.type}`) }}
                                <span class="text-muted">({{ dtNum(s.units) }}
                                    {{ s.type === 'hourly' ? 'h' : (s.type === 'per_meter' ? 'm' : $t('attendance.unit_days')) }}
                                    × {{ eur(s.rate) }})</span>
                            </dt>
                            <dd>{{ eur(s.amount) }}</dd>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex justify-between gap-4">
                            <dt><Bilingual k="payroll.attendance_days" inline /> <span class="text-muted">({{ breakdown.attendance_days }})</span></dt>
                            <dd>{{ eur(breakdown.days_amount) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt><Bilingual k="payroll.attendance_hours" inline /> <span class="text-muted">({{ hoursHM(breakdown.attendance_hours) }})</span></dt>
                            <dd>{{ eur(breakdown.hours_amount) }}</dd>
                        </div>
                    </template>
                    <div class="flex justify-between gap-4">
                        <dt><Bilingual k="payroll.reimbursements" inline /></dt>
                        <dd>{{ eur(breakdown.reimbursements) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt><Bilingual k="payroll.project_expenses" inline /></dt>
                        <dd>{{ eur(breakdown.project_expenses) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt><Bilingual k="payroll.overtime_pay" inline /> <span class="text-muted">({{ hoursHM(breakdown.overtime_hours) }})</span></dt>
                        <dd>{{ eur(breakdown.overtime_pay) }}</dd>
                    </div>

                    <div class="flex justify-between gap-4 border-t border-line pt-2 font-semibold">
                        <dt><Bilingual k="payroll.gross" inline /></dt>
                        <dd>{{ eur(breakdown.gross_pay) }}</dd>
                    </div>

                    <div class="flex justify-between gap-4 pt-2 text-status-danger">
                        <dt><Bilingual k="payroll.advance_deductions" inline /></dt>
                        <dd>− {{ eur(breakdown.advance_deductions) }}</dd>
                    </div>
                    <div v-if="breakdown.fine_deductions" class="flex justify-between gap-4 text-status-danger">
                        <dt><Bilingual k="payroll.fine_deductions" inline /></dt>
                        <dd>− {{ eur(breakdown.fine_deductions) }}</dd>
                    </div>
                    <div v-if="breakdown.expense_deductions" class="flex justify-between gap-4 text-status-danger">
                        <dt><Bilingual k="payroll.expense_deductions" inline /></dt>
                        <dd>− {{ eur(breakdown.expense_deductions) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 text-status-danger">
                        <dt><Bilingual k="payroll.other_deductions" inline /></dt>
                        <dd>− {{ eur(breakdown.other_deductions) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 text-status-ok">
                        <dt><Bilingual k="payroll.manual_additions" inline /></dt>
                        <dd>+ {{ eur(breakdown.manual_additions) }}</dd>
                    </div>

                    <div class="flex justify-between gap-4 border-t-2 border-line-strong pt-2 text-base font-bold"
                        :class="Number(breakdown.net_amount) < 0 ? 'text-status-danger' : ''">
                        <dt><Bilingual k="payroll.net_pay" inline /></dt>
                        <dd>{{ eur(breakdown.net_amount) }}</dd>
                    </div>
                </dl>

                <!-- Net pay went negative: advances/deductions exceed gross. -->
                <p v-if="Number(breakdown.net_amount) < 0"
                    class="rounded-md bg-status-warn-soft px-3 py-2 text-xs text-status-warn">
                    {{ $t('payroll.net_negative_warning') }}
                </p>

                <p v-if="breakdown.notes" class="rounded-md bg-surface-sunken px-3 py-2 text-xs text-ink-soft">
                    {{ breakdown.notes }}
                </p>
            </div>
            <template #footer>
                <VButton variant="ghost" @click="breakdownId = null"><Bilingual k="common.close" inline /></VButton>
            </template>
        </VModal>

        <!-- Manual adjustments -->
        <VModal :open="adjusting !== null" title-key="payroll.adjustments" @close="adjustingId = null">
            <form id="adjust-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitAdjust">
                <FormField k="payroll.other_deductions" :error="adjustForm.errors.other_deductions">
                    <VInput v-model="adjustForm.other_deductions" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="payroll.manual_additions" :error="adjustForm.errors.manual_additions">
                    <VInput v-model="adjustForm.manual_additions" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="payroll.notes" class="sm:col-span-2">
                    <VTextarea v-model="adjustForm.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="adjustingId = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="adjust-form" :loading="adjustForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- New / edit advance (deducted from the chosen payroll month) -->
        <VModal :open="advanceOpen" :title-key="editingAdvanceId ? 'advances.edit' : 'payroll.new_advance'" @close="advanceOpen = false">
            <form id="advance-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitAdvance">
                <FormField k="payroll.advance_employee" :error="advanceForm.errors.employee_id" class="sm:col-span-2">
                    <VSelect v-model="advanceForm.employee_id" :disabled="!!editingAdvanceId">
                        <option value="" disabled>—</option>
                        <option v-for="r in rows" :key="r.employee_id" :value="r.employee_id">{{ r.employee }}</option>
                    </VSelect>
                </FormField>
                <FormField k="payroll.advance_amount" :error="advanceForm.errors.amount">
                    <VInput v-model="advanceForm.amount" type="number" step="0.01" min="0.01" />
                </FormField>
                <FormField k="payroll.advance_month" :error="advanceForm.errors.payroll_month">
                    <VInput v-model="advanceForm.payroll_month" type="month" />
                </FormField>
                <FormField k="payroll.advance_date" :error="advanceForm.errors.request_date">
                    <VInput v-model="advanceForm.request_date" type="date" />
                </FormField>
                <!-- How it was paid out (Issue 2) -->
                <FormField k="advances.payment_method" :error="advanceForm.errors.payment_method">
                    <VSelect v-model="advanceForm.payment_method">
                        <option value="cash">{{ $t('advances.pm_cash') }}</option>
                        <option value="bank_transfer">{{ $t('advances.pm_bank_transfer') }}</option>
                    </VSelect>
                </FormField>
                <!-- Bank transfer → proof-of-transfer receipt; Cash → reason below. -->
                <FormField v-if="advanceForm.payment_method === 'bank_transfer'" k="advances.receipt" :error="advanceForm.errors.receipt">
                    <input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="block w-full text-sm text-ink-soft file:mr-3 file:rounded-md file:border-0 file:bg-surface-sunken file:px-3 file:py-1.5 file:text-sm file:text-ink" @change="pickReceipt" />
                    <p v-if="advanceForm.receipt" class="mt-1 truncate text-xs text-ink-soft">{{ advanceForm.receipt.name }}</p>
                </FormField>
                <FormField k="payroll.advance_reason" class="sm:col-span-2">
                    <VTextarea v-model="advanceForm.reason" :rows="2" :placeholder="$t('advances.reason_hint')" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="advanceOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="advance-form" :loading="advanceForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
