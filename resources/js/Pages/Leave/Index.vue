<script setup>
/**
 * Screen 22 — Leave Management. Two views: the requests table and the
 * balances table. Approving books the days into the attendance grid, which is
 * why the confirm copy says so rather than just "approve".
 */
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VFileDrop from '@/Components/ui/VFileDrop.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    leaves: { type: Object, required: true },
    filters: { type: Object, required: true },
    employees: { type: Array, required: true },
    categories: { type: Array, required: true },
    statuses: { type: Array, required: true },
    balances: { type: Array, required: true },
    can: { type: Object, required: true },
});

const view = ref('requests');
const tabs = [
    { key: 'requests', labelKey: 'leave.requests' },
    { key: 'balances', labelKey: 'leave.balances' },
];

const filters = reactive({
    employee_id: props.filters.employee_id ?? '',
    leave_category_id: props.filters.leave_category_id ?? '',
    status: props.filters.status ?? '',
    per_page: Number(props.filters.per_page ?? 25),
});
function apply(extra = {}) {
    router.get('/leave', { ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}

/* Request */
const showModal = ref(false);
const blank = {
    employee_id: '', leave_category_id: '', start_date: null, end_date: null,
    total_days: null, reason: '', attachment: null,
};
const form = useForm({ ...blank });

function open() {
    if (!ensureCompanySelected()) return;

    Object.keys(blank).forEach((k) => { form[k] = blank[k]; });
    form.clearErrors();
    showModal.value = true;
}
function submit() {
    form.post('/leave', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (showModal.value = false),
    });
}

// Auto-fill "Total days" from the working days (Mon–Fri) between the two dates,
// so the request can't be rejected for a blank or mismatched count. The admin
// can still override it (e.g. a half day).
watch([() => form.start_date, () => form.end_date], ([start, end]) => {
    if (!start || !end) return;
    const s = new Date(start);
    const e = new Date(end);
    if (Number.isNaN(s.getTime()) || Number.isNaN(e.getTime()) || e < s) return;
    let weekdays = 0;
    for (const d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
        const dow = d.getDay();
        if (dow !== 0 && dow !== 6) weekdays++;
    }
    form.total_days = weekdays || '';
});

/* Review */
const reviewing = ref(null);
const reviewAction = ref('approve');
const reviewForm = useForm({ review_notes: '' });

function review(leave, action) {
    reviewing.value = leave;
    reviewAction.value = action;
    reviewForm.reset();
    reviewForm.clearErrors();
}
function confirmReview() {
    reviewForm.post(`/leave/${reviewing.value.id}/${reviewAction.value}`, {
        preserveScroll: true,
        onSuccess: () => (reviewing.value = null),
    });
}

/* Balance adjustment */
const adjusting = ref(null);
const balanceForm = useForm({ allocated: 0, carried_over: 0 });

function adjust(balance) {
    adjusting.value = balance;
    balanceForm.allocated = balance.allocated;
    balanceForm.carried_over = balance.carried_over;
    balanceForm.clearErrors();
}
function saveBalance() {
    balanceForm.put(`/leave-balances/${adjusting.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (adjusting.value = null),
    });
}

const statusTone = { pending: 'warn', approved: 'ok', rejected: 'danger', cancelled: 'neutral' };

const requestColumns = [
    { key: 'employee', labelKey: 'leave.employee' },
    { key: 'category', labelKey: 'leave.category' },
    { key: 'start_date', labelKey: 'leave.start_date' },
    { key: 'end_date', labelKey: 'leave.end_date' },
    { key: 'total_days', labelKey: 'leave.total_days', align: 'end' },
    { key: 'status', labelKey: 'leave.status' },
    { key: 'reviewed_by', labelKey: 'leave.reviewed_by' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];

const balanceColumns = [
    { key: 'employee', labelKey: 'leave.employee' },
    { key: 'category', labelKey: 'leave.category' },
    { key: 'year', labelKey: 'leave.year' },
    { key: 'allocated', labelKey: 'leave.allocated', align: 'end' },
    { key: 'used', labelKey: 'leave.used', align: 'end' },
    { key: 'pending', labelKey: 'leave.pending', align: 'end' },
    { key: 'carried_over', labelKey: 'leave.carried_over', align: 'end' },
    { key: 'remaining', labelKey: 'leave.remaining', align: 'end' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
</script>

<template>
    <Head :title="$t('leave.title')" />
    <AppLayout>
        <VPageHeader k="leave.title">
            <VButton v-if="can.create" icon="plus" @click="open()"><Bilingual k="leave.new" inline /></VButton>
        </VPageHeader>

        <VTabs v-model="view" :tabs="tabs" />

        <template v-if="view === 'requests'">
            <div class="flex flex-wrap items-end gap-2 py-3">
                <VSelect v-model="filters.employee_id" class="w-full sm:w-56" @update:model-value="apply()">
                    <option value="">{{ $t('leave.employee') }}</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                </VSelect>
                <VSelect v-model="filters.leave_category_id" class="w-full sm:w-56" @update:model-value="apply()">
                    <option value="">{{ $t('leave.category') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </VSelect>
                <VSelect v-model="filters.status" class="w-full sm:w-48" @update:model-value="apply()">
                    <option value="">{{ $t('leave.status') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ $t(`leave.status_${s}`) }}</option>
                </VSelect>
            </div>

            <VTable :columns="requestColumns">
                <tr v-for="l in leaves.data" :key="l.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm">{{ l.employee ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">
                        {{ l.category }}
                        <VBadge v-if="!l.is_paid" status="neutral" class="ms-1.5">
                            <Bilingual k="leave.unpaid" inline />
                        </VBadge>
                    </td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ l.start_date }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ l.end_date }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ l.total_days }}</td>
                    <td class="px-3 py-2.5">
                        <VBadge :status="statusTone[l.status]">
                            <Bilingual :k="`leave.status_${l.status}`" inline />
                        </VBadge>
                    </td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ l.reviewed_by ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-end">
                        <span class="flex items-center justify-end gap-1.5">
                            <a v-if="l.has_attachment" :href="`/leave/${l.id}/attachment`"
                                class="text-sm text-accent hover:underline">
                                <Bilingual k="leave.attachment" inline />
                            </a>
                            <VButton v-if="can.approve && l.status === 'pending'" variant="ghost" size="sm"
                                @click="review(l, 'approve')">
                                <Bilingual k="leave.approve" inline />
                            </VButton>
                            <VButton v-if="can.approve && l.status === 'pending'" variant="ghost" size="sm"
                                @click="review(l, 'reject')">
                                <Bilingual k="leave.reject" inline />
                            </VButton>
                            <VButton v-if="can.edit && ['pending', 'approved'].includes(l.status)" variant="ghost"
                                size="sm" @click="review(l, 'cancel')">
                                <Bilingual k="leave.cancel" inline />
                            </VButton>
                        </span>
                    </td>
                </tr>
                <template v-if="leaves.data.length === 0" #empty><VEmptyState icon="leave" /></template>
            </VTable>

            <VPagination :page="leaves.current_page" :pages="leaves.last_page" :per-page="filters.per_page"
                :total="leaves.total" @update:page="(p) => apply({ page: p })"
                @update:per-page="(pp) => { filters.per_page = pp; apply(); }" />
        </template>

        <template v-else>
            <VTable :columns="balanceColumns" class="mt-3">
                <tr v-for="b in balances" :key="b.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm">{{ b.employee ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ b.category }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ b.year }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ b.allocated }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ b.used }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm text-ink-soft">{{ b.pending }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm text-ink-soft">{{ b.carried_over }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm font-medium">{{ b.remaining }}</td>
                    <td class="px-3 py-2.5 text-end">
                        <VButton v-if="can.approve" variant="ghost" size="sm" icon="edit" @click="adjust(b)" />
                    </td>
                </tr>
                <template v-if="balances.length === 0" #empty><VEmptyState icon="leave" /></template>
            </VTable>
        </template>

        <!-- New request -->
        <VModal :open="showModal" title-key="leave.new" @close="showModal = false">
            <form id="leave-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormField k="leave.employee" :error="form.errors.employee_id" required>
                    <VSelect v-model="form.employee_id">
                        <option value="">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="leave.category" :error="form.errors.leave_category_id" required>
                    <VSelect v-model="form.leave_category_id">
                        <option value="">—</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="leave.start_date" :error="form.errors.start_date" required>
                    <VDateInput v-model="form.start_date" />
                </FormField>
                <FormField k="leave.end_date" :error="form.errors.end_date" required>
                    <VDateInput v-model="form.end_date" />
                </FormField>
                <FormField k="leave.total_days" :error="form.errors.total_days" required>
                    <VInput v-model="form.total_days" type="number" step="0.5" min="0.5" />
                </FormField>
                <FormField k="leave.attachment" :error="form.errors.attachment">
                    <VFileDrop v-model="form.attachment" accept=".pdf,.jpg,.jpeg,.png" />
                </FormField>
                <FormField k="leave.reason" :error="form.errors.reason" class="sm:col-span-2">
                    <VTextarea v-model="form.reason" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="leave-form" :loading="form.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Approve / reject / cancel -->
        <VModal :open="reviewing !== null" :title-key="`leave.${reviewAction}`" size="sm" @close="reviewing = null">
            <form id="review-form" class="grid gap-4" @submit.prevent="confirmReview">
                <FormField k="leave.review_notes" :error="reviewForm.errors.review_notes">
                    <VTextarea v-model="reviewForm.review_notes" :rows="3" />
                </FormField>
                <p v-if="reviewForm.errors.start_date" class="text-sm text-status-danger">
                    {{ reviewForm.errors.start_date }}
                </p>
                <p v-if="reviewForm.errors.status" class="text-sm text-status-danger">{{ reviewForm.errors.status }}</p>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="reviewing = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="review-form" :loading="reviewForm.processing">
                    <Bilingual :k="`leave.${reviewAction}`" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Adjust balance -->
        <VModal :open="adjusting !== null" title-key="leave.adjust_balance" size="sm" @close="adjusting = null">
            <form id="balance-form" class="grid gap-4" @submit.prevent="saveBalance">
                <FormField k="leave.allocated" :error="balanceForm.errors.allocated" required>
                    <VInput v-model="balanceForm.allocated" type="number" step="0.5" min="0" />
                </FormField>
                <FormField k="leave.carried_over" :error="balanceForm.errors.carried_over" required>
                    <VInput v-model="balanceForm.carried_over" type="number" step="0.5" min="0" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="adjusting = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="balance-form" :loading="balanceForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
