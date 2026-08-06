<script setup>
/**
 * Screen 10 — Invoices. Ventas (money in) and Gastos (money out) as two tabs on
 * one screen, with the detail in a slide panel.
 *
 * Every total shown here is computed server-side by InvoiceTotals; the form
 * only ever sends line items + rates. The live preview below mirrors that same
 * arithmetic so the user sees it before saving — the server remains the
 * authority.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VSlideOver from '@/Components/ui/VSlideOver.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VVatSelect from '@/Components/ui/VVatSelect.vue';

const props = defineProps({
    tab: { type: String, required: true },
    invoices: { type: Object, required: true },
    filters: { type: Object, required: true },
    clients: { type: Array, required: true },
    vendors: { type: Array, required: true },
    projects: { type: Array, required: true },
    vatOptions: { type: Array, required: true },
    paymentMethods: { type: Array, required: true },
    paymentStatuses: { type: Array, required: true },
    statuses: { type: Array, required: true },
    editing: { type: Object, default: null },
    can: { type: Object, required: true },
});

const tabs = [
    { key: 'sale', labelKey: 'invoices.tab_sales' },
    { key: 'expense', labelKey: 'invoices.tab_expenses' },
];

const filters = reactive({
    search: props.filters.search ?? '',
    payment_status: props.filters.payment_status ?? '',
    project_id: props.filters.project_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

// Built in the script (URLSearchParams is a real global here, unlike in a
// template binding) — the "export the filtered view" URL for the header link.
const exportUrl = computed(
    () => `/invoices/export?${new URLSearchParams({ tab: props.tab, ...filters }).toString()}`,
);

function apply(extra = {}) {
    router.get('/invoices', { tab: props.tab, ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}

function switchTab(tab) {
    router.get('/invoices', { tab }, { preserveScroll: true });
}

/* ---------- create / edit ---------- */
const panelOpen = ref(false);
const editingId = ref(null);

const blank = {
    type: 'sale', sub_type: 'final', client_id: '', vendor_id: '', project_id: '',
    invoice_date: null, due_date: null, billing_type: '', billing_period: '',
    vat_rate: null, discount_type: '', discount_value: 0, retention_percent: null,
    status: 'draft', payment_method: '', payment_date: null, notes: '',
    lines: [{ description: '', quantity: 1, unit_price: 0 }],
};
const form = useForm({ ...structuredClone(blank) });

function openCreate() {
    // An invoice belongs to one company; a Super Admin browsing all companies
    // is sent to the picker first (no-op for everyone else).
    if (!ensureCompanySelected()) return;

    editingId.value = null;
    Object.assign(form, structuredClone(blank));
    form.type = props.tab; // the tab you are on decides sale vs expense
    form.clearErrors();
    panelOpen.value = true;
}

function addLine() {
    form.lines.push({ description: '', quantity: 1, unit_price: 0 });
}

function removeLine(i) {
    if (form.lines.length > 1) form.lines.splice(i, 1);
}

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => (panelOpen.value = false) };
    const payload = form.transform((d) => ({
        ...d,
        client_id: d.client_id || null,
        vendor_id: d.vendor_id || null,
        project_id: d.project_id || null,
        discount_type: d.discount_type || null,
        payment_method: d.payment_method || null,
    }));
    editingId.value ? payload.put(`/invoices/${editingId.value}`, opts) : payload.post('/invoices', opts);
}

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }

function destroy(row) {
    askDelete(row.reference ?? row.number ?? '',
        () => router.delete(`/invoices/${row.id}`, { preserveScroll: true }));
}

function openEdit(row) {
    editingId.value = row.id;
    router.get(`/invoices/${row.id}`, {}, {
        only: ['editing'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const e = props.editing;
            if (!e) return;
            Object.assign(form, {
                type: e.type,
                sub_type: e.sub_type,
                client_id: e.client_id ?? '',
                vendor_id: e.vendor_id ?? '',
                project_id: e.project_id ?? '',
                invoice_date: e.invoice_date,
                due_date: e.due_date ?? null,
                billing_type: e.billing_type ?? '',
                billing_period: e.billing_period ?? '',
                vat_rate: e.vat_rate ?? null,
                discount_type: e.discount_type ?? '',
                discount_value: e.discount_value,
                retention_percent: e.retention_percent,
                status: e.status,
                payment_method: e.payment_method ?? '',
                payment_date: e.payment_date ?? null,
                notes: e.notes ?? '',
                lines: e.lines?.length
                    ? e.lines.map((l) => ({ description: l.description, quantity: l.quantity, unit_price: l.unit_price }))
                    : [{ description: '', quantity: 1, unit_price: 0 }],
            });
            form.clearErrors();
            panelOpen.value = true;
        },
    });
}

/* ---------- payment log (edit mode only) ---------- */
const payForm = useForm({ amount: '', payment_date: '', payment_method: '', reference: '' });
const editingPayments = computed(() => props.editing?.payments ?? []);

function logPayment() {
    payForm.post(`/invoices/${editingId.value}/payments`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            payForm.reset();
            router.get(`/invoices/${editingId.value}`, {}, { only: ['editing'], preserveScroll: true, preserveState: true });
        },
    });
}

function deletePayment(paymentId) {
    router.delete(`/payments/${paymentId}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            router.get(`/invoices/${editingId.value}`, {}, { only: ['editing'], preserveScroll: true, preserveState: true });
        },
    });
}

/* ---------- live preview of the server's arithmetic ---------- */
const preview = computed(() => {
    const subtotal = form.lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
    const rate = props.vatOptions.find((o) => o.value === form.vat_rate);
    let discount = 0;
    if (form.discount_type === 'percent') discount = subtotal * (Number(form.discount_value) || 0) / 100;
    if (form.discount_type === 'fixed') discount = Number(form.discount_value) || 0;
    discount = Math.min(discount, subtotal);
    const base = subtotal - discount;
    const vat = rate?.percent ? base * rate.percent / 100 : 0;
    const retention = base * (Number(form.retention_percent) || 0) / 100;
    return { subtotal, discount, vat, retention, total: base + vat - retention };
});

const paymentBadge = { unpaid: 'danger', partial: 'warn', paid: 'ok', pending: 'neutral' };
const statusBadge = { draft: 'neutral', sent: 'info', paid: 'ok' };

function eur(n) {
    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(n ?? 0));
}

const columns = computed(() => [
    { key: 'number', labelKey: 'invoices.number' },
    { key: 'party', labelKey: props.tab === 'sale' ? 'invoices.client' : 'invoices.vendor' },
    { key: 'project', labelKey: 'invoices.project' },
    { key: 'invoice_date', labelKey: 'invoices.invoice_date' },
    { key: 'due_date', labelKey: 'invoices.due_date' },
    { key: 'vat', labelKey: 'invoices.vat', align: 'end' },
    { key: 'total', labelKey: 'invoices.total', align: 'end' },
    { key: 'payment_status', labelKey: 'invoices.payment_status' },
    { key: 'status', labelKey: 'invoices.status' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
]);
</script>

<template>
    <Head :title="$t('invoices.title')" />
    <AppLayout>
        <VPageHeader k="invoices.title">
            <!-- Exports the CURRENT filtered view — same query as the table.
                 The href is a computed: `new URLSearchParams` cannot live in a
                 template binding (Vue prefixes non-whitelisted globals with the
                 component proxy, so it became $.URLSearchParams → a render crash
                 that took the whole header, New Invoice button included, down). -->
            <a v-if="can.export" :href="exportUrl"
                class="inline-flex items-center gap-2 rounded-md border border-line bg-surface-raised px-3.5 py-2 text-sm font-medium text-ink hover:bg-surface-hover">
                <AppIcon name="export" class="h-4 w-4" /> Excel
            </a>
            <VButton v-if="can.create" icon="plus" @click="openCreate">
                <Bilingual k="invoices.new" inline />
            </VButton>
        </VPageHeader>

        <VTabs :tabs="tabs" :model-value="tab" class="mb-4" @update:model-value="switchTab" />

        <div class="space-y-2 py-3">
            <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <VSelect v-model="filters.payment_status" @update:model-value="apply()">
                    <option value="">{{ $t('invoices.payment_status') }}</option>
                    <option v-for="s in paymentStatuses" :key="s" :value="s">
                        {{ $t(`invoices.payment_${s}`) }}
                    </option>
                </VSelect>
                <VSelect v-model="filters.project_id" @update:model-value="apply()">
                    <option value="">{{ $t('invoices.project') }}</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </VSelect>
                <VDateInput v-model="filters.from" @update:model-value="apply()" />
                <VDateInput v-model="filters.to" @update:model-value="apply()" />
            </div>
        </div>

        <VTable :columns="columns">
            <tr v-for="r in invoices.data" :key="r.id" class="hover:bg-surface-hover">
                <td class="px-3 py-2.5 text-sm font-medium text-ink">
                    {{ r.number }}
                    <VBadge v-if="r.sub_type === 'pre'" status="neutral" class="ms-1">
                        <Bilingual k="invoices.sub_type_pre" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5 text-sm">{{ r.client ?? r.vendor ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ r.project ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-sm">{{ r.invoice_date }}</td>
                <td class="tabular-nums px-3 py-2.5 text-sm text-ink-soft">{{ r.due_date ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm text-ink-soft">
                    <!-- blank VAT shows "—", never 0% (design-skill VAT rule) -->
                    {{ r.vat_percent !== null && r.vat_percent !== undefined ? `${r.vat_percent}%` : '—' }}
                </td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm font-semibold">{{ eur(r.total) }}</td>
                <td class="px-3 py-2.5">
                    <VBadge :status="paymentBadge[r.payment_status] ?? 'neutral'">
                        <Bilingual :k="`invoices.payment_${r.payment_status}`" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5">
                    <VBadge :status="statusBadge[r.status] ?? 'neutral'">
                        <Bilingual :k="`invoices.status_${r.status}`" inline />
                    </VBadge>
                </td>
                <td class="px-3 py-2.5 text-end">
                    <span class="flex items-center justify-end gap-1.5">
                        <button v-if="can.edit" class="rounded-sm p-1.5 text-muted hover:text-ink"
                            :title="$t('invoices.edit')" @click="openEdit(r)">
                            <AppIcon name="edit" class="h-3.5 w-3.5" />
                        </button>
                        <a v-if="can.export" :href="`/invoices/${r.id}/pdf`" class="rounded-sm p-1.5 text-muted hover:text-ink" title="PDF">
                            <AppIcon name="download" class="h-3.5 w-3.5" />
                        </a>
                        <VButton v-if="can.delete" variant="ghost" size="sm" icon="trash" @click="destroy(r)" />
                    </span>
                </td>
            </tr>
            <template v-if="invoices.data.length === 0" #empty>
                <VEmptyState icon="invoices" message-key="invoices.no_rows" />
            </template>
        </VTable>

        <VPagination :page="invoices.current_page" :pages="invoices.last_page" :total="invoices.total"
            @update:page="(p) => apply({ page: p })" />

        <!-- Detail / create — slide panel, never a separate page -->
        <VSlideOver :open="panelOpen" :title-key="editingId ? 'invoices.edit' : 'invoices.new'"
            width="md:max-w-3xl" @close="panelOpen = false">
            <form id="invoice-form" class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="invoices.sub_type" :error="form.errors.sub_type" required>
                        <VSelect v-model="form.sub_type">
                            <option value="final">{{ $t('invoices.sub_type_final') }}</option>
                            <option value="pre">{{ $t('invoices.sub_type_pre') }}</option>
                        </VSelect>
                    </FormField>
                    <FormField k="invoices.status" :error="form.errors.status" required>
                        <VSelect v-model="form.status">
                            <option v-for="s in statuses" :key="s" :value="s">
                                {{ $t(`invoices.status_${s}`) }}
                            </option>
                        </VSelect>
                    </FormField>

                    <FormField v-if="form.type === 'sale'" k="invoices.client" :error="form.errors.client_id" required>
                        <VSelect v-model="form.client_id">
                            <option value="">—</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </VSelect>
                    </FormField>
                    <FormField v-else k="invoices.vendor" :error="form.errors.vendor_id" required>
                        <VSelect v-model="form.vendor_id">
                            <option value="">—</option>
                            <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
                        </VSelect>
                    </FormField>

                    <FormField k="invoices.project" :error="form.errors.project_id">
                        <VSelect v-model="form.project_id">
                            <option value="">—</option>
                            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </VSelect>
                    </FormField>

                    <FormField k="invoices.invoice_date" :error="form.errors.invoice_date" required>
                        <VDateInput v-model="form.invoice_date" />
                    </FormField>
                    <FormField k="invoices.due_date" :error="form.errors.due_date">
                        <VDateInput v-model="form.due_date" />
                    </FormField>
                </div>

                <!-- Line items -->
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <Bilingual k="invoices.lines" class="text-[13px] font-semibold" />
                        <VButton variant="secondary" size="sm" icon="plus" @click="addLine">
                            <Bilingual k="invoices.add_line" inline />
                        </VButton>
                    </div>
                    <div class="space-y-2">
                        <div v-for="(line, i) in form.lines" :key="i" class="grid grid-cols-12 items-center gap-2">
                            <VInput v-model="line.description" class="col-span-6"
                                :placeholder="$t('invoices.description')" />
                            <VInput v-model="line.quantity" type="number" step="0.01" min="0" class="col-span-2" />
                            <VInput v-model="line.unit_price" type="number" step="0.01" min="0" class="col-span-3" />
                            <button type="button" class="col-span-1 rounded-sm p-1.5 text-muted hover:text-status-danger"
                                :disabled="form.lines.length === 1" @click="removeLine(i)">
                                <AppIcon name="trash" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                    <p v-if="form.errors.lines" class="mt-1 text-xs text-status-danger">{{ form.errors.lines }}</p>
                </div>

                <!-- Rates -->
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="invoices.vat" :error="form.errors.vat_rate">
                        <VVatSelect v-model="form.vat_rate" :options="vatOptions" />
                    </FormField>
                    <FormField k="invoices.retention" :error="form.errors.retention_percent">
                        <VInput v-model="form.retention_percent" type="number" step="0.01" min="0" max="100" />
                    </FormField>
                    <FormField k="invoices.discount_type" :error="form.errors.discount_type">
                        <VSelect v-model="form.discount_type">
                            <option value="">—</option>
                            <option value="percent">{{ $t('invoices.discount_percent') }}</option>
                            <option value="fixed">{{ $t('invoices.discount_fixed') }}</option>
                        </VSelect>
                    </FormField>
                    <FormField k="invoices.discount" :error="form.errors.discount_value">
                        <VInput v-model="form.discount_value" type="number" step="0.01" min="0" />
                    </FormField>
                </div>

                <!-- Totals preview (the server recomputes these on save) -->
                <dl class="tabular-nums space-y-1 rounded-lg border border-line bg-surface-sunken p-3 text-sm">
                    <div class="flex justify-between">
                        <dt><Bilingual k="invoices.subtotal" inline /></dt>
                        <dd>{{ eur(preview.subtotal) }}</dd>
                    </div>
                    <div v-if="preview.discount > 0" class="flex justify-between text-status-danger">
                        <dt><Bilingual k="invoices.discount" inline /></dt>
                        <dd>− {{ eur(preview.discount) }}</dd>
                    </div>
                    <div v-if="form.vat_rate" class="flex justify-between">
                        <dt><Bilingual k="invoices.vat" inline /></dt>
                        <dd>{{ eur(preview.vat) }}</dd>
                    </div>
                    <div v-if="preview.retention > 0" class="flex justify-between text-status-danger">
                        <dt><Bilingual k="invoices.retention" inline /></dt>
                        <dd>− {{ eur(preview.retention) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-line pt-1 text-base font-bold">
                        <dt><Bilingual k="invoices.total" inline /></dt>
                        <dd>{{ eur(preview.total) }}</dd>
                    </div>
                </dl>

                <FormField k="invoices.notes"><VTextarea v-model="form.notes" :rows="2" /></FormField>
            </form>

            <!-- ══════════ Payments (edit mode only) ══════════ -->
            <template v-if="editingId">
                <div class="mt-6 border-t border-line pt-5 space-y-3">
                    <Bilingual k="invoices.payments" class="text-[13px] font-semibold" />

                    <!-- Existing payments list -->
                    <div v-if="editingPayments.length" class="space-y-1">
                        <div v-for="p in editingPayments" :key="p.id"
                            class="flex items-center justify-between rounded-md border border-line bg-surface-sunken px-3 py-2 text-sm">
                            <span class="tabular-nums font-medium">{{ eur(p.amount) }}</span>
                            <span class="text-ink-soft">{{ p.payment_date }}</span>
                            <span class="text-ink-soft">{{ p.payment_method ?? '—' }}</span>
                            <span class="text-ink-soft">{{ p.reference ?? '—' }}</span>
                            <button v-if="can.edit" class="rounded-sm p-1 text-muted hover:text-status-danger"
                                @click="deletePayment(p.id)">
                                <AppIcon name="trash" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                    <p v-else class="text-sm text-muted">
                        <Bilingual k="invoices.no_payments" inline />
                    </p>

                    <!-- Log payment mini-form -->
                    <div v-if="can.edit" class="rounded-lg border border-line bg-surface-raised p-3 space-y-3">
                        <Bilingual k="invoices.add_payment" class="text-xs font-semibold uppercase tracking-wide text-muted" />
                        <div class="grid gap-2 sm:grid-cols-2">
                            <FormField k="invoices.amount" :error="payForm.errors.amount" required>
                                <VInput v-model="payForm.amount" type="number" step="0.01" min="0.01" />
                            </FormField>
                            <FormField k="invoices.payment_date" :error="payForm.errors.payment_date" required>
                                <VDateInput v-model="payForm.payment_date" />
                            </FormField>
                            <FormField k="invoices.payment_method" :error="payForm.errors.payment_method">
                                <VSelect v-model="payForm.payment_method">
                                    <option value="">—</option>
                                    <option v-for="m in paymentMethods" :key="m" :value="m">
                                        {{ $t(`invoices.payment_method_${m}`) }}
                                    </option>
                                </VSelect>
                            </FormField>
                            <FormField k="invoices.reference" :error="payForm.errors.reference">
                                <VInput v-model="payForm.reference" />
                            </FormField>
                        </div>
                        <div class="flex justify-end">
                            <VButton variant="secondary" size="sm" :loading="payForm.processing" @click="logPayment">
                                <Bilingual k="invoices.add_payment" inline />
                            </VButton>
                        </div>
                    </div>
                </div>
            </template>

            <template #footer>
                <VButton variant="ghost" @click="panelOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="invoice-form" :loading="form.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VSlideOver>
        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
