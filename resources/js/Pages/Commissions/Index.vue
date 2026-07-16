<script setup>
/**
 * Screen 19 — Commission Reports.
 *
 * Finalizing locks an entry for good, so it asks for confirmation first (spec).
 * The original commission is always shown next to the adjusted one — the pair
 * plus the reason is the audit story, so the UI never hides the original.
 */
import { computed, reactive, ref } from 'vue';
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
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    month: { type: String, required: true },
    entries: { type: Array, required: true },
    filters: { type: Object, required: true },
    employees: { type: Array, required: true },
    projects: { type: Array, required: true },
    statuses: { type: Array, required: true },
    total: { type: Number, default: 0 },
    can: { type: Object, required: true },
});

const page = usePage();

const filters = reactive({
    employee_id: props.filters.employee_id ?? '',
    project_id: props.filters.project_id ?? '',
    status: props.filters.status ?? '',
});

function apply(extra = {}) {
    router.get('/commissions', { month: props.month, ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}

function changeMonth(delta) {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    router.get('/commissions', { month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` },
        { preserveScroll: true });
}

const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Intl.DateTimeFormat(page.props.locale.primary === 'es' ? 'es-ES' : 'en-GB',
        { month: 'long', year: 'numeric' }).format(new Date(y, m - 1, 1));
});

function generate() {
    router.post('/commissions/generate', { month: props.month }, { preserveScroll: true });
}

function finalize(row) {
    // One-way door — confirm first (spec: "requires confirmation")
    if (!window.confirm(page.props.lang[page.props.locale.primary].commissions.confirm_finalize)) return;
    router.post(`/commissions/${row.id}/finalize`, {}, { preserveScroll: true });
}

function markPaid(row) {
    router.post(`/commissions/${row.id}/paid`, {}, { preserveScroll: true });
}

/* ---------- adjust ---------- */
const adjustingId = ref(null);
const adjusting = computed(() => props.entries.find((e) => e.id === adjustingId.value) ?? null);
const adjustForm = useForm({ adjusted_amount: 0, adjustment_reason: '' });

function openAdjust(row) {
    adjustingId.value = row.id;
    adjustForm.adjusted_amount = row.adjusted_amount ?? row.original_amount;
    adjustForm.adjustment_reason = row.adjustment_reason ?? '';
    adjustForm.clearErrors();
}

function submitAdjust() {
    adjustForm.put(`/commissions/${adjustingId.value}/adjust`, {
        preserveScroll: true,
        onSuccess: () => (adjustingId.value = null),
    });
}

function eur(n) {
    if (n === null || n === undefined) return '—';
    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(n));
}

const statusBadge = { draft: 'neutral', finalized: 'info', paid: 'ok' };

const columns = [
    { key: 'employee', labelKey: 'commissions.employee' },
    { key: 'project', labelKey: 'commissions.project' },
    { key: 'invoice', labelKey: 'commissions.invoice' },
    { key: 'percent', labelKey: 'commissions.percent', align: 'end' },
    { key: 'invoice_total', labelKey: 'commissions.invoice_total', align: 'end' },
    { key: 'invoice_paid', labelKey: 'commissions.invoice_paid', align: 'end' },
    { key: 'original', labelKey: 'commissions.original', align: 'end' },
    { key: 'adjusted', labelKey: 'commissions.adjusted', align: 'end' },
    { key: 'amount', labelKey: 'commissions.amount', align: 'end' },
    { key: 'status', labelKey: 'commissions.status' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
</script>

<template>
    <Head title="Comisiones" />
    <AppLayout>
        <VPageHeader k="commissions.title">
            <VButton v-if="can.edit" variant="secondary" icon="plus" @click="generate">
                <Bilingual k="commissions.generate" inline />
            </VButton>
            <a v-if="can.export" :href="`/commissions/pdf?month=${month}`"
                class="inline-flex items-center gap-2 rounded-md border border-line bg-surface-raised px-3.5 py-2 text-sm font-medium text-ink hover:bg-surface-hover">
                <AppIcon name="export" class="h-4 w-4" /> PDF
            </a>
        </VPageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="changeMonth(-1)">
                <AppIcon name="chevron-left" class="h-4 w-4" />
            </button>
            <span class="min-w-40 text-center text-sm font-semibold capitalize">{{ monthLabel }}</span>
            <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="changeMonth(1)">
                <AppIcon name="chevron-right" class="h-4 w-4" />
            </button>
            <span class="tabular-nums ms-auto text-sm">
                <Bilingual k="commissions.total" inline class="text-ink-soft" />
                <strong class="ms-2">{{ eur(total) }}</strong>
            </span>
        </div>

        <div class="grid grid-cols-2 gap-2 pb-3 lg:grid-cols-3">
            <VSelect v-model="filters.employee_id" @update:model-value="apply()">
                <option value="">{{ $page.props.lang.es.commissions.employee }}</option>
                <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
            </VSelect>
            <VSelect v-model="filters.project_id" @update:model-value="apply()">
                <option value="">{{ $page.props.lang.es.commissions.project }}</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </VSelect>
            <VSelect v-model="filters.status" @update:model-value="apply()">
                <option value="">{{ $page.props.lang.es.commissions.status }}</option>
                <option v-for="s in statuses" :key="s" :value="s">
                    {{ $page.props.lang.es.commissions[`status_${s}`] }}
                </option>
            </VSelect>
        </div>

        <VTable :columns="columns">
            <tr v-for="r in entries" :key="r.id" class="hover:bg-surface-hover">
                <td class="px-3 py-2.5 text-sm font-medium text-ink">{{ r.employee }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ r.project ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ r.invoice ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ r.commission_percent }}%</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ eur(r.invoice_total) }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm text-ink-soft">{{ eur(r.invoice_paid) }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm text-ink-soft">{{ eur(r.original_amount) }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">
                    <span v-if="r.adjusted_amount !== null" :title="r.adjustment_reason">{{ eur(r.adjusted_amount) }}</span>
                    <span v-else class="text-muted">—</span>
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm font-semibold">{{ eur(r.amount) }}</td>
                <td class="px-3 py-2.5">
                    <VBadge :status="statusBadge[r.status] ?? 'neutral'">
                        <Bilingual :k="`commissions.status_${r.status}`" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5 text-end">
                    <span class="flex items-center justify-end gap-1.5">
                        <VButton v-if="can.edit && r.status === 'draft'" variant="ghost" size="sm" icon="edit"
                            @click="openAdjust(r)">
                            <Bilingual k="commissions.adjust" inline />
                        </VButton>
                        <VButton v-if="can.approve && r.status === 'draft'" variant="ghost" size="sm" @click="finalize(r)">
                            <Bilingual k="commissions.finalize" inline />
                        </VButton>
                        <VButton v-if="can.approve && r.status === 'finalized'" variant="ghost" size="sm" @click="markPaid(r)">
                            <Bilingual k="commissions.mark_paid" inline />
                        </VButton>
                    </span>
                </td>
            </tr>
            <template v-if="entries.length === 0" #empty>
                <VEmptyState icon="commissions" message-key="commissions.no_rows" />
            </template>
        </VTable>

        <VModal :open="adjusting !== null" title-key="commissions.adjust" @close="adjustingId = null">
            <form v-if="adjusting" id="adjust-commission" class="space-y-4" @submit.prevent="submitAdjust">
                <!-- The original always stays visible next to the adjustment -->
                <dl class="tabular-nums space-y-1 rounded-md bg-surface-sunken p-3 text-sm">
                    <div class="flex justify-between">
                        <dt><Bilingual k="commissions.original" inline /></dt>
                        <dd>{{ eur(adjusting.original_amount) }}</dd>
                    </div>
                </dl>
                <FormField k="commissions.adjusted" :error="adjustForm.errors.adjusted_amount" required>
                    <VInput v-model="adjustForm.adjusted_amount" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="commissions.adjustment_reason" :error="adjustForm.errors.adjustment_reason" required>
                    <VTextarea v-model="adjustForm.adjustment_reason" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="adjustingId = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="adjust-commission" :loading="adjustForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
