<script setup>
/**
 * Subcontractor detail — the thaekedar DEAL (confirmed model 2026-08-13).
 * Four tabs: Workers (our employees LIVE from attendance + external manual),
 * Expenses (who bears them follows the deal), Settlement (the live waterfall:
 * budget − deductions = thaekedar profit; − paid = still to pay), Our P&L.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppLayout from '@/Layouts/AppLayout.vue';
import SubcontractorFormModal from '@/Components/Subcontractors/SubcontractorFormModal.vue';
import WorkerFormModal from '@/Components/Subcontractors/WorkerFormModal.vue';
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VTabs from '@/Components/ui/VTabs.vue';

const props = defineProps({
    subcontractor: { type: Object, required: true },
    workers: { type: Array, default: () => [] },
    payments: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    settlement: { type: Object, required: true },
    expenses: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const tab = ref('workers');
const showEdit = ref(false);
const showWorker = ref(false);
const editingWorker = ref(null);
const confirm = ref({ open: false, message: '', fn: null });

const statusVariant = { active: 'ok', completed: 'info', cancelled: 'danger' };
const payVariant = { pending: 'warn', partial: 'info', paid: 'ok' };

const externalWorkers = computed(() => props.workers.filter((w) => !w.is_our_employee));
const ourWorkerLinks = computed(() => props.workers.filter((w) => w.is_our_employee));
const thaekedarBears = computed(() => props.subcontractor.expense_responsibility === 'thaekedar');

const tabs = computed(() => [
    { key: 'workers', labelKey: 'subcontractors.tab_workers', count: props.workers.length },
    { key: 'expenses', labelKey: 'subcontractors.tab_expenses', count: props.expenses.length },
    { key: 'settlement', labelKey: 'subcontractors.tab_settlement' },
    { key: 'pnl', labelKey: 'subcontractors.tab_pnl' },
]);

function eur(v) {
    return `${Number(v ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}
function ask(message, fn) { confirm.value = { open: true, message, fn }; }
function run() { confirm.value.fn?.(); confirm.value.open = false; }

const base = `/subcontractors/${props.subcontractor.id}`;

function newWorker() { editingWorker.value = null; showWorker.value = true; }
function editWorker(w) { editingWorker.value = w; showWorker.value = true; }
function deleteWorker(w) {
    ask(w.name, () => router.delete(`${base}/workers/${w.id}`, { preserveScroll: true }));
}

const paymentForm = useForm({ payment_date: null, amount: null, notes: '' });
function addPayment() {
    paymentForm.post(`${base}/payments`, { preserveScroll: true, onSuccess: () => paymentForm.reset() });
}
function markPaid(p) { router.post(`${base}/payments/${p.id}/paid`, {}, { preserveScroll: true }); }
function markPending(p) { router.post(`${base}/payments/${p.id}/pending`, {}, { preserveScroll: true }); }
function deletePayment(p) {
    ask(`${t('subcontractors.payment_number')} ${p.payment_number}`,
        () => router.delete(`${base}/payments/${p.id}`, { preserveScroll: true }));
}
function destroy() {
    ask(props.subcontractor.name, () => router.delete(base));
}
</script>

<template>
    <Head :title="subcontractor.name" />
    <AppLayout>
        <!-- Header -->
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-lg font-semibold">{{ subcontractor.name }}</h1>
                    <VBadge :status="statusVariant[subcontractor.status]">{{ t(`subcontractors.status_${subcontractor.status}`) }}</VBadge>
                </div>
                <p class="mt-0.5 text-sm text-muted">
                    {{ subcontractor.project ?? '—' }}
                    <span v-if="subcontractor.nif" class="ms-2">· {{ subcontractor.nif }}</span>
                    <span v-if="subcontractor.phone" class="ms-2">· {{ subcontractor.phone }}</span>
                </p>
            </div>
            <div class="flex gap-2">
                <VButton v-if="can.edit" variant="secondary" size="sm" icon="edit" @click="showEdit = true">
                    <Bilingual k="common.edit" inline />
                </VButton>
                <VButton v-if="can.delete" variant="danger" size="sm" icon="trash" @click="destroy">
                    <Bilingual k="common.delete" inline />
                </VButton>
            </div>
        </div>

        <!-- The DEAL: client amount · thaekedar budget · our margin · who bears expenses -->
        <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <VCard :padded="false" class="px-4 py-3">
                <p class="text-xs text-muted"><Bilingual k="subcontractors.client_amount" inline /></p>
                <p class="tabular-nums mt-1 text-lg font-semibold">{{ subcontractor.client_amount !== null ? eur(subcontractor.client_amount) : '—' }}</p>
            </VCard>
            <VCard :padded="false" class="px-4 py-3">
                <p class="text-xs text-muted"><Bilingual k="subcontractors.agreed_budget" inline /></p>
                <p class="tabular-nums mt-1 text-lg font-semibold">{{ subcontractor.agreed_budget !== null ? eur(subcontractor.agreed_budget) : '—' }}</p>
            </VCard>
            <VCard :padded="false" class="px-4 py-3">
                <p class="text-xs text-muted"><Bilingual k="subcontractors.our_profit" inline /></p>
                <p class="tabular-nums mt-1 text-lg font-semibold"
                    :class="settlement.our_profit === null ? '' : (settlement.our_profit >= 0 ? 'text-status-ok' : 'text-status-danger')">
                    {{ settlement.our_profit !== null ? eur(settlement.our_profit) : '—' }}
                </p>
            </VCard>
            <VCard :padded="false" class="px-4 py-3">
                <p class="text-xs text-muted"><Bilingual k="subcontractors.expense_responsibility" inline /></p>
                <VBadge :status="thaekedarBears ? 'info' : 'warn'" class="mt-1.5">
                    {{ t(`subcontractors.resp_badge_${subcontractor.expense_responsibility}`) }}
                </VBadge>
            </VCard>
        </div>

        <p v-if="subcontractor.agreed_budget === null" class="mb-4 rounded-md bg-status-warn-soft px-3 py-2 text-sm text-status-warn">
            {{ t('subcontractors.no_budget_hint') }}
        </p>

        <VTabs v-model="tab" :tabs="tabs" class="mb-4" />

        <!-- ── Tab 1: Workers ─────────────────────────────────────────────── -->
        <template v-if="tab === 'workers'">
            <VCard class="mb-5">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-[15px] font-semibold"><Bilingual k="subcontractors.section_our" /></h2>
                    <VButton v-if="can.edit" size="sm" icon="plus" variant="secondary" @click="newWorker">
                        <Bilingual k="subcontractors.link_employee" inline />
                    </VButton>
                </div>
                <p class="mb-3 text-xs text-muted">{{ t('subcontractors.our_live_hint') }}</p>
                <VEmptyState v-if="!settlement.our_employees.length" icon="employees" title-key="subcontractors.section_our" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase text-muted">
                                <th class="px-2 py-2 text-start font-medium"><Bilingual k="subcontractors.worker_name" inline /></th>
                                <th class="px-2 py-2 text-start font-medium"><Bilingual k="employees.designation" inline /></th>
                                <th class="px-2 py-2 text-end font-medium"><Bilingual k="subcontractors.days_worked" inline /></th>
                                <th class="px-2 py-2 text-end font-medium"><Bilingual k="employees.rate" inline /></th>
                                <th class="px-2 py-2 text-end font-medium"><Bilingual k="subcontractors.total_agreed" inline /></th>
                                <th v-if="can.edit" class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in settlement.our_employees" :key="line.employee_id" class="border-b border-line last:border-0">
                                <td class="px-2 py-2">{{ line.name }}</td>
                                <td class="px-2 py-2 text-ink-soft">{{ line.designation ?? '—' }}</td>
                                <td class="tabular-nums px-2 py-2 text-end">{{ line.days }}</td>
                                <td class="tabular-nums px-2 py-2 text-end">{{ line.rate !== null ? eur(line.rate) : '—' }}</td>
                                <td class="tabular-nums px-2 py-2 text-end font-medium">{{ eur(line.total) }}</td>
                                <td v-if="can.edit" class="px-2 py-2 text-end">
                                    <button v-for="link in ourWorkerLinks.filter((w) => w.employee_id === line.employee_id)" :key="link.id"
                                        type="button" class="p-1 text-muted hover:text-status-danger"
                                        :title="t('subcontractors.unlink')" @click="deleteWorker(link)">
                                        <AppIcon name="trash" class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-line font-semibold">
                                <td class="px-2 py-2" colspan="4"><Bilingual k="subcontractors.our_total" inline /></td>
                                <td class="tabular-nums px-2 py-2 text-end">{{ eur(settlement.our_employees_total) }}</td>
                                <td v-if="can.edit"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </VCard>

            <VCard>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-[15px] font-semibold"><Bilingual k="subcontractors.section_external" /></h2>
                    <VButton v-if="can.edit" size="sm" icon="plus" @click="newWorker">
                        <Bilingual k="subcontractors.add_worker" inline />
                    </VButton>
                </div>
                <VEmptyState v-if="!externalWorkers.length" icon="employees" title-key="subcontractors.section_external" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase text-muted">
                                <th class="px-2 py-2 text-start font-medium"><Bilingual k="subcontractors.worker_name" inline /></th>
                                <th class="px-2 py-2 text-end font-medium"><Bilingual k="subcontractors.days_worked" inline /></th>
                                <th class="px-2 py-2 text-end font-medium"><Bilingual k="subcontractors.agreed_rate" inline /></th>
                                <th class="px-2 py-2 text-end font-medium"><Bilingual k="subcontractors.total_agreed" inline /></th>
                                <th class="px-2 py-2 text-start font-medium"><Bilingual k="subcontractors.pay_status" inline /></th>
                                <th v-if="can.edit" class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="w in externalWorkers" :key="w.id" class="border-b border-line last:border-0">
                                <td class="px-2 py-2">{{ w.name }}</td>
                                <td class="tabular-nums px-2 py-2 text-end">{{ w.days_worked }}</td>
                                <td class="tabular-nums px-2 py-2 text-end">{{ eur(w.agreed_rate) }}</td>
                                <td class="tabular-nums px-2 py-2 text-end font-medium">{{ eur(w.total_agreed) }}</td>
                                <td class="px-2 py-2">
                                    <VBadge :status="payVariant[w.payment_status]">{{ t(`subcontractors.pay_${w.payment_status}`) }}</VBadge>
                                </td>
                                <td v-if="can.edit" class="px-2 py-2 text-end">
                                    <button type="button" class="p-1 text-muted hover:text-ink" @click="editWorker(w)"><AppIcon name="edit" class="h-4 w-4" /></button>
                                    <button type="button" class="p-1 text-muted hover:text-status-danger" @click="deleteWorker(w)"><AppIcon name="trash" class="h-4 w-4" /></button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-line font-semibold">
                                <td class="px-2 py-2" colspan="3"><Bilingual k="subcontractors.external_total" inline /></td>
                                <td class="tabular-nums px-2 py-2 text-end">{{ eur(settlement.external_workers_total) }}</td>
                                <td colspan="2" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </VCard>
        </template>

        <!-- ── Tab 2: Expenses ────────────────────────────────────────────── -->
        <template v-else-if="tab === 'expenses'">
            <p class="mb-3 rounded-md px-3 py-2 text-sm"
                :class="thaekedarBears ? 'bg-status-info-soft text-status-info' : 'bg-status-warn-soft text-status-warn'">
                {{ t(thaekedarBears ? 'subcontractors.expenses_banner_thaekedar' : 'subcontractors.expenses_banner_ours') }}
            </p>
            <VCard :padded="false">
                <VEmptyState v-if="!expenses.length" icon="euro" title-key="subcontractors.tab_expenses" class="p-6" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase text-muted">
                                <th class="px-3 py-2 text-start font-medium"><Bilingual k="expenses.date" inline /></th>
                                <th class="px-3 py-2 text-start font-medium"><Bilingual k="expenses.number" inline /></th>
                                <th class="px-3 py-2 text-start font-medium"><Bilingual k="expenses.category" inline /></th>
                                <th class="px-3 py-2 text-end font-medium"><Bilingual k="expenses.total" inline /></th>
                                <th class="px-3 py-2 text-start font-medium"><Bilingual k="expenses.approval" inline /></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in expenses" :key="e.id" class="border-b border-line last:border-0">
                                <td class="tabular-nums px-3 py-2">{{ e.date }}</td>
                                <td class="px-3 py-2">{{ e.number ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ e.category ?? '—' }}</td>
                                <td class="tabular-nums px-3 py-2 text-end font-medium">{{ eur(e.total) }}</td>
                                <td class="px-3 py-2">
                                    <VBadge :status="e.approved ? 'ok' : 'warn'">
                                        <Bilingual :k="e.approved ? 'expenses.is_approved' : 'expenses.pending'" inline />
                                    </VBadge>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-line font-semibold">
                                <td class="px-3 py-2" colspan="3"><Bilingual k="subcontractors.expenses_total" inline /></td>
                                <td class="tabular-nums px-3 py-2 text-end">{{ eur(settlement.expenses_total) }}</td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </VCard>
        </template>

        <!-- ── Tab 3: Settlement — the live waterfall ─────────────────────── -->
        <template v-else-if="tab === 'settlement'">
            <div class="grid gap-5 lg:grid-cols-2">
                <VCard>
                    <h2 class="mb-3 text-[15px] font-semibold"><Bilingual k="subcontractors.tab_settlement" /></h2>
                    <dl class="tabular-nums space-y-1.5 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt><Bilingual k="subcontractors.agreed_budget" inline /></dt>
                            <dd class="font-medium">{{ settlement.agreed_budget !== null ? eur(settlement.agreed_budget) : '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 text-status-danger">
                            <dt><Bilingual k="subcontractors.our_salaries" inline /> <span class="text-xs text-muted">({{ t('subcontractors.live_from_attendance') }})</span></dt>
                            <dd>− {{ eur(settlement.our_employees_total) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 text-status-danger">
                            <dt><Bilingual k="subcontractors.section_external" inline /></dt>
                            <dd>− {{ eur(settlement.external_workers_total) }}</dd>
                        </div>
                        <div v-if="thaekedarBears" class="flex justify-between gap-4 text-status-danger">
                            <dt><Bilingual k="subcontractors.project_expenses" inline /></dt>
                            <dd>− {{ eur(settlement.expenses_total) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 border-t border-line pt-2 font-semibold">
                            <dt><Bilingual k="subcontractors.thaekedar_profit" inline /></dt>
                            <dd>{{ settlement.thaekedar_profit !== null ? eur(settlement.thaekedar_profit) : '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 pt-2 text-status-ok">
                            <dt><Bilingual k="subcontractors.payments_made" inline /></dt>
                            <dd>− {{ eur(settlement.paid_so_far) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 border-t border-line pt-2 text-base font-semibold">
                            <dt><Bilingual k="subcontractors.still_to_pay" inline /></dt>
                            <dd :class="settlement.still_to_pay !== null && settlement.still_to_pay < 0 ? 'text-status-danger' : ''">
                                {{ settlement.still_to_pay !== null ? eur(settlement.still_to_pay) : '—' }}
                            </dd>
                        </div>
                    </dl>
                </VCard>

                <!-- Payment history + add -->
                <VCard>
                    <h2 class="mb-3 text-[15px] font-semibold"><Bilingual k="subcontractors.payments" /></h2>
                    <div v-if="payments.length" class="space-y-2">
                        <div v-for="p in payments" :key="p.id"
                            class="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-md border border-line bg-surface-sunken px-3 py-2.5">
                            <span class="text-sm font-semibold">{{ t('subcontractors.payment_number') }} {{ p.payment_number }}</span>
                            <span class="tabular-nums text-sm text-ink-soft">{{ p.payment_date ?? '—' }}</span>
                            <span class="tabular-nums text-sm font-medium">{{ eur(p.amount) }}</span>
                            <VBadge :status="payVariant[p.status]">{{ t(`subcontractors.pay_${p.status}`) }}</VBadge>
                            <div v-if="can.edit" class="ms-auto flex items-center gap-2">
                                <VButton v-if="p.status !== 'paid'" size="sm" variant="secondary" @click="markPaid(p)">
                                    <Bilingual k="subcontractors.mark_paid" inline />
                                </VButton>
                                <VButton v-else size="sm" variant="ghost" @click="markPending(p)">
                                    <Bilingual k="subcontractors.mark_pending" inline />
                                </VButton>
                                <button type="button" class="p-1 text-muted hover:text-status-danger" @click="deletePayment(p)"><AppIcon name="trash" class="h-4 w-4" /></button>
                            </div>
                        </div>
                    </div>
                    <VEmptyState v-else icon="euro" title-key="subcontractors.payments" />

                    <form v-if="can.edit" class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4" @submit.prevent="addPayment">
                        <FormField k="subcontractors.payment_date" class="w-36" :error="paymentForm.errors.payment_date">
                            <VDateInput v-model="paymentForm.payment_date" />
                        </FormField>
                        <FormField k="subcontractors.amount" class="w-36" :error="paymentForm.errors.amount">
                            <VCurrencyInput v-model="paymentForm.amount" />
                        </FormField>
                        <FormField k="subcontractors.notes" class="min-w-32 flex-1" :error="paymentForm.errors.notes">
                            <VInput v-model="paymentForm.notes" />
                        </FormField>
                        <VButton type="submit" icon="plus" :loading="paymentForm.processing">
                            <Bilingual k="subcontractors.add_payment" inline />
                        </VButton>
                    </form>
                    <p class="mt-2 text-xs text-muted"><Bilingual k="subcontractors.auto_expense_hint" /></p>
                </VCard>
            </div>
        </template>

        <!-- ── Tab 4: Our P&L ─────────────────────────────────────────────── -->
        <template v-else-if="tab === 'pnl'">
            <VCard class="max-w-xl">
                <h2 class="mb-3 text-[15px] font-semibold"><Bilingual k="subcontractors.tab_pnl" /></h2>
                <dl class="tabular-nums space-y-1.5 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt><Bilingual k="subcontractors.pnl_client" inline /></dt>
                        <dd class="font-medium">{{ settlement.client_amount !== null ? eur(settlement.client_amount) : '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 text-status-danger">
                        <dt><Bilingual k="subcontractors.pnl_thaekedar" inline /></dt>
                        <dd>− {{ settlement.agreed_budget !== null ? eur(settlement.agreed_budget) : '—' }}</dd>
                    </div>
                    <div v-if="!thaekedarBears" class="flex justify-between gap-4 text-status-danger">
                        <dt><Bilingual k="subcontractors.pnl_our_expenses" inline /></dt>
                        <dd>− {{ eur(settlement.expenses_total) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-line pt-2 text-base font-semibold">
                        <dt><Bilingual k="subcontractors.pnl_net" inline /></dt>
                        <dd :class="settlement.our_profit === null ? '' : (settlement.our_profit >= 0 ? 'text-status-ok' : 'text-status-danger')">
                            {{ settlement.our_profit !== null ? eur(settlement.our_profit) : '—' }}
                        </dd>
                    </div>
                </dl>
                <dl class="tabular-nums mt-4 space-y-1 border-t border-line pt-3 text-sm text-ink-soft">
                    <div class="flex justify-between gap-4">
                        <dt><Bilingual k="subcontractors.status" inline /></dt>
                        <dd>{{ t(`subcontractors.status_${subcontractor.status}`) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt><Bilingual k="subcontractors.payments_made" inline /></dt>
                        <dd>{{ eur(settlement.paid_so_far) }}<template v-if="settlement.thaekedar_profit !== null"> / {{ eur(settlement.thaekedar_profit) }}</template></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt><Bilingual k="subcontractors.still_to_pay" inline /></dt>
                        <dd>{{ settlement.still_to_pay !== null ? eur(settlement.still_to_pay) : '—' }}</dd>
                    </div>
                </dl>
            </VCard>
        </template>

        <SubcontractorFormModal :open="showEdit" :subcontractor="subcontractor" :projects="projects" :statuses="statuses" @close="showEdit = false" />
        <WorkerFormModal :open="showWorker" :subcontractor-id="subcontractor.id" :worker="editingWorker"
            :employees="employees" @close="showWorker = false" />
        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="run" @cancel="confirm.open = false" />
    </AppLayout>
</template>
