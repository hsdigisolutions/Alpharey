<script setup>
/**
 * Subcontractor detail — the record, the workers they brought, and the payment
 * schedule. Marking a payment paid posts a Gasto on the subcontractor's company.
 */
import { ref } from 'vue';
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

const props = defineProps({
    subcontractor: { type: Object, required: true },
    workers: { type: Array, default: () => [] },
    payments: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    projects: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const showEdit = ref(false);
const showWorker = ref(false);
const editingWorker = ref(null);
const confirm = ref({ open: false, message: '', fn: null });

const statusVariant = { active: 'ok', completed: 'info', cancelled: 'danger' };
const payVariant = { pending: 'warn', partial: 'info', paid: 'ok' };

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
                <p class="mt-0.5 text-sm text-muted">{{ subcontractor.project ?? '—' }}</p>
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

        <div class="grid gap-5 lg:grid-cols-3">
            <!-- Record info -->
            <VCard>
                <dl class="divide-y divide-line text-sm">
                    <div v-for="row in [
                        { k: 'subcontractors.nif', v: subcontractor.nif },
                        { k: 'subcontractors.phone', v: subcontractor.phone },
                        { k: 'subcontractors.email', v: subcontractor.email },
                        { k: 'subcontractors.start_date', v: subcontractor.start_date },
                        { k: 'subcontractors.end_date', v: subcontractor.end_date },
                    ]" :key="row.k" class="flex justify-between gap-4 py-2">
                        <dt class="text-muted"><Bilingual :k="row.k" inline /></dt>
                        <dd class="text-end">{{ row.v ?? '—' }}</dd>
                    </div>
                </dl>
                <p v-if="subcontractor.notes" class="mt-3 whitespace-pre-line border-t border-line pt-3 text-sm text-ink-soft">{{ subcontractor.notes }}</p>
            </VCard>

            <!-- Workers -->
            <VCard class="lg:col-span-2">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-[15px] font-semibold"><Bilingual k="subcontractors.workers" /></h2>
                    <VButton v-if="can.edit" size="sm" icon="plus" @click="newWorker">
                        <Bilingual k="subcontractors.add_worker" inline />
                    </VButton>
                </div>
                <VEmptyState v-if="!workers.length" icon="employees" title-key="subcontractors.workers" />
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
                            <tr v-for="w in workers" :key="w.id" class="border-b border-line last:border-0">
                                <td class="px-2 py-2">
                                    {{ w.name }}
                                    <VBadge v-if="w.is_our_employee" status="info" class="ms-1">{{ w.employee ?? '—' }}</VBadge>
                                </td>
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
                    </table>
                </div>
            </VCard>
        </div>

        <!-- Payment schedule -->
        <VCard class="mt-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-[15px] font-semibold"><Bilingual k="subcontractors.payments" /></h2>
            </div>

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

            <!-- Totals -->
            <dl class="mt-4 space-y-1 border-t border-line pt-3 text-sm">
                <div class="flex justify-between"><dt class="text-muted"><Bilingual k="subcontractors.total_agreed" inline /></dt><dd class="tabular-nums">{{ eur(summary.payments_total) }}</dd></div>
                <div class="flex justify-between text-status-ok"><dt><Bilingual k="subcontractors.total_paid" inline /></dt><dd class="tabular-nums">{{ eur(summary.paid_total) }}</dd></div>
                <div class="flex justify-between font-semibold"><dt><Bilingual k="subcontractors.pending" inline /></dt><dd class="tabular-nums">{{ eur(summary.pending) }}</dd></div>
            </dl>

            <!-- Add payment -->
            <form v-if="can.edit" class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4" @submit.prevent="addPayment">
                <FormField k="subcontractors.payment_date" class="w-40" :error="paymentForm.errors.payment_date">
                    <VDateInput v-model="paymentForm.payment_date" />
                </FormField>
                <FormField k="subcontractors.amount" class="w-40" :error="paymentForm.errors.amount">
                    <VCurrencyInput v-model="paymentForm.amount" />
                </FormField>
                <FormField k="subcontractors.notes" class="min-w-40 flex-1" :error="paymentForm.errors.notes">
                    <VInput v-model="paymentForm.notes" />
                </FormField>
                <VButton type="submit" icon="plus" :loading="paymentForm.processing">
                    <Bilingual k="subcontractors.add_payment" inline />
                </VButton>
            </form>
            <p class="mt-2 text-xs text-muted"><Bilingual k="subcontractors.auto_expense_hint" /></p>
        </VCard>

        <SubcontractorFormModal :open="showEdit" :subcontractor="subcontractor" :projects="projects" :statuses="statuses" @close="showEdit = false" />
        <WorkerFormModal :open="showWorker" :subcontractor-id="subcontractor.id" :worker="editingWorker"
            :employees="employees" @close="showWorker = false" />
        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="run" @cancel="confirm.open = false" />
    </AppLayout>
</template>
