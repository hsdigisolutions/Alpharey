<script setup>
/**
 * Screen 12 — Payroll. Calculate → review breakdown → adjust → Approve All →
 * mark paid → Lock Period.
 *
 * Every money figure arrives already gated on `payroll.view` server-side and is
 * null otherwise — this page never decides who may see pay, it just renders
 * what it was given.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    month: { type: String, required: true },
    rows: { type: Array, required: true },
    summary: { type: Object, required: true },
    locked: { type: Boolean, default: false },
    paymentMethods: { type: Array, required: true },
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

function markPaid(row) {
    router.post(`/payroll/${row.id}/paid`, { payment_method: row.payment_method }, { preserveScroll: true });
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

const columns = [
    { key: 'employee', labelKey: 'payroll.employee' },
    { key: 'days', labelKey: 'payroll.days', align: 'end' },
    { key: 'hours', labelKey: 'payroll.hours', align: 'end' },
    { key: 'wage_type', labelKey: 'payroll.wage_type' },
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

        <VTable :columns="columns">
            <tr v-for="r in rows" :key="r.id" class="hover:bg-surface-hover">
                <td class="px-3 py-2.5 text-sm">
                    <span class="block font-medium text-ink">{{ r.employee }}</span>
                    <span class="block text-xs text-muted">{{ r.designation ?? '—' }}</span>
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ r.attendance_days }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ r.attendance_hours }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">
                    <Bilingual v-if="r.wage_type" :k="`employees.wage_${r.wage_type}`" inline />
                    <span v-else>—</span>
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ eur(r.gross_pay) }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm text-status-danger">
                    {{ r.advance_deductions ? `− ${eur(r.advance_deductions)}` : '—' }}
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm font-semibold">{{ eur(r.net_amount) }}</td>
                <td class="px-3 py-2.5">
                    <VBadge :status="r.status === 'paid' ? 'ok' : 'warn'">
                        <Bilingual :k="`payroll.status_${r.status}`" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5 text-end">
                    <span class="flex items-center justify-end gap-1.5">
                        <VButton variant="ghost" size="sm" icon="eye" @click="breakdownId = r.id">
                            <Bilingual k="payroll.breakdown" inline />
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

        <!-- Breakdown modal — layout per REQUIREMENTS.md Screen 12 -->
        <VModal :open="breakdown !== null" title-key="payroll.breakdown" @close="breakdownId = null">
            <div v-if="breakdown" class="space-y-4">
                <p class="text-sm font-semibold">{{ breakdown.employee }}</p>

                <div v-if="breakdown.deployment_notes?.length" class="space-y-1">
                    <p v-for="(note, i) in breakdown.deployment_notes" :key="i"
                        class="rounded-md bg-status-info-soft px-3 py-2 text-xs text-status-info">{{ note }}</p>
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
                            <dt><Bilingual k="payroll.attendance_hours" inline /> <span class="text-muted">({{ breakdown.attendance_hours }} h)</span></dt>
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
                        <dt><Bilingual k="payroll.overtime_pay" inline /> <span class="text-muted">({{ breakdown.overtime_hours }} h)</span></dt>
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
                    <div class="flex justify-between gap-4 text-status-danger">
                        <dt><Bilingual k="payroll.other_deductions" inline /></dt>
                        <dd>− {{ eur(breakdown.other_deductions) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 text-status-ok">
                        <dt><Bilingual k="payroll.manual_additions" inline /></dt>
                        <dd>+ {{ eur(breakdown.manual_additions) }}</dd>
                    </div>

                    <div class="flex justify-between gap-4 border-t-2 border-line-strong pt-2 text-base font-bold">
                        <dt><Bilingual k="payroll.net_pay" inline /></dt>
                        <dd>{{ eur(breakdown.net_amount) }}</dd>
                    </div>
                </dl>

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
    </AppLayout>
</template>
