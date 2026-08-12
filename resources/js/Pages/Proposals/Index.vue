<script setup>
/**
 * Screen 18 — Proposals. Shared pool. Line items + optional VAT dropdown;
 * totals computed server-side. Detail in a slide-over.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VSlideOver from '@/Components/ui/VSlideOver.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VVatSelect from '@/Components/ui/VVatSelect.vue';

const props = defineProps({
    proposals: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    clients: { type: Array, required: true },
    vatOptions: { type: Array, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    per_page: Number(props.filters.per_page ?? 25),
});
let timer = null;
watch(() => filters.search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 350); });
function apply(extra = {}) { router.get('/proposals', { ...filters, ...extra }, { preserveScroll: true, preserveState: true }); }

const showPanel = ref(false);
const editing = ref(null);
const blank = { client_id: '', project_id: '', proposal_date: null, expiry_date: null, description: '', line_items: [], vat_rate: null, vat_custom_percent: null, status: 'draft', notes: '' };
const form = useForm({ ...blank });

function open(proposal = null) {
    editing.value = proposal;
    Object.keys(blank).forEach((k) => { form[k] = proposal?.[k] ?? (k === 'line_items' ? [] : blank[k]); });
    if (!form.line_items) form.line_items = [];
    form.clearErrors();
    showPanel.value = true;
}
function addLine() { form.line_items.push({ description: '', qty: 1, unit_price: 0 }); }
function removeLine(i) { form.line_items.splice(i, 1); }

const subtotal = computed(() => form.line_items.reduce((s, it) => s + (Number(it.qty) || 0) * (Number(it.unit_price) || 0), 0));
const vatPct = computed(() => {
    if (form.vat_rate === 'custom') return Number(form.vat_custom_percent) || 0;
    return props.vatOptions.find((o) => o.value === form.vat_rate)?.percent ?? null;
});
const vatAmount = computed(() => vatPct.value !== null ? Math.round(subtotal.value * vatPct.value) / 100 : null);
const total = computed(() => subtotal.value + (vatAmount.value ?? 0));

function submit() {
    const payload = form.transform((d) => ({ ...d, client_id: d.client_id || null, project_id: d.project_id || null }));
    const opts = { preserveScroll: true, onSuccess: () => (showPanel.value = false) };
    editing.value ? payload.put(`/proposals/${editing.value.id}`, opts) : payload.post('/proposals', opts);
}

const statusBadge = { draft: 'neutral', sent: 'info', approved: 'ok', rejected: 'danger' };

const columns = [
    { key: 'number', labelKey: 'proposals.number' },
    { key: 'client', labelKey: 'proposals.client' },
    { key: 'date', labelKey: 'proposals.date' },
    { key: 'expiry', labelKey: 'proposals.expiry' },
    { key: 'total', labelKey: 'proposals.total', align: 'end' },
    { key: 'status', labelKey: 'proposals.status' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
</script>

<template>
    <Head :title="$t('proposals.title')" />
    <AppLayout>
        <VPageHeader k="proposals.title">
            <VButton v-if="can.create" icon="plus" @click="open()"><Bilingual k="proposals.new" inline /></VButton>
        </VPageHeader>

        <div class="flex flex-wrap items-end gap-2 pb-3">
            <div class="w-full sm:w-56"><VSearchInput v-model="filters.search" /></div>
            <VSelect v-model="filters.status" class="w-full sm:w-52" @update:model-value="apply()">
                <option value="">{{ $t('proposals.status') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ $t(`proposals.status_${s}`) }}</option>
            </VSelect>
        </div>

        <VTable :columns="columns">
            <tr v-for="p in proposals.data" :key="p.id" class="hover:bg-surface-hover">
                <td class="tabular-nums px-3 py-2.5 text-sm font-medium">{{ p.number }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ p.client ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-sm text-ink-soft">{{ p.proposal_date ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-sm text-ink-soft">{{ p.expiry_date ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ p.total_amount }} €</td>
                <td class="px-3 py-2.5"><VBadge :status="statusBadge[p.status]"><Bilingual :k="`proposals.status_${p.status}`" inline /></VBadge></td>
                <td class="px-3 py-2.5 text-end">
                    <span class="flex items-center justify-end gap-1">
                        <button v-if="can.export" type="button" class="rounded-md p-1.5 text-ink-soft hover:bg-surface-sunken" aria-label="PDF" @click="() => (window.location.href = `/proposals/${p.id}/pdf`)">
                            <AppIcon name="download" class="h-4 w-4" />
                        </button>
                        <button v-if="can.edit" type="button" class="rounded-md p-1.5 text-ink-soft hover:bg-surface-sunken" aria-label="Editar" @click="open(p)">
                            <AppIcon name="edit" class="h-4 w-4" />
                        </button>
                    </span>
                </td>
            </tr>
            <template v-if="proposals.data.length === 0" #empty><VEmptyState icon="file" /></template>
        </VTable>

        <VPagination :page="proposals.current_page" :pages="proposals.last_page" :per-page="filters.per_page"
            :total="proposals.total" @update:page="(p) => apply({ page: p })" @update:per-page="(pp) => { filters.per_page = pp; apply(); }" />

        <!-- Detail slide-over -->
        <VSlideOver :open="showPanel" :title-key="editing ? 'proposals.edit' : 'proposals.new'" @close="showPanel = false">
            <form id="proposal-form" class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="proposals.client" :error="form.errors.client_id">
                        <VSelect v-model="form.client_id">
                            <option value="">—</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </VSelect>
                    </FormField>
                    <FormField k="proposals.status" required>
                        <VSelect v-model="form.status">
                            <option v-for="s in statuses" :key="s" :value="s">{{ $t(`proposals.status_${s}`) }}</option>
                        </VSelect>
                    </FormField>
                    <FormField k="proposals.date"><VDateInput v-model="form.proposal_date" /></FormField>
                    <FormField k="proposals.expiry" :error="form.errors.expiry_date"><VDateInput v-model="form.expiry_date" /></FormField>
                </div>
                <FormField k="proposals.description"><VTextarea v-model="form.description" :rows="2" /></FormField>

                <!-- Line items -->
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <Bilingual k="proposals.line_items" class="text-sm font-semibold" />
                        <VButton type="button" variant="secondary" size="sm" icon="plus" @click="addLine"><Bilingual k="proposals.add_line" inline /></VButton>
                    </div>
                    <div v-for="(item, i) in form.line_items" :key="i" class="mb-2 grid grid-cols-[1fr_70px_100px_auto] items-end gap-2">
                        <VInput v-model="item.description" :placeholder="$t('proposals.item_desc')" />
                        <VInput v-model="item.qty" type="number" step="0.01" placeholder="Cant." />
                        <VCurrencyInput v-model="item.unit_price" />
                        <button type="button" class="rounded-md p-2 text-status-danger hover:bg-status-danger-soft" @click="removeLine(i)"><AppIcon name="trash" class="h-4 w-4" /></button>
                    </div>
                </div>

                <!-- VAT + totals -->
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="proposals.vat" :error="form.errors.vat_custom_percent"><VVatSelect v-model="form.vat_rate" v-model:custom-percent="form.vat_custom_percent" :options="vatOptions" /></FormField>
                    <div class="rounded-md bg-surface-sunken p-3 text-sm">
                        <p class="tabular-nums flex justify-between"><span><Bilingual k="proposals.subtotal" inline /></span><span>{{ subtotal.toFixed(2) }} €</span></p>
                        <p class="tabular-nums flex justify-between text-ink-soft"><span>IVA / VAT</span><span>{{ vatAmount !== null ? vatAmount.toFixed(2) + ' €' : '—' }}</span></p>
                        <p class="tabular-nums flex justify-between border-t border-line pt-1 font-semibold"><span><Bilingual k="proposals.total" inline /></span><span>{{ total.toFixed(2) }} €</span></p>
                    </div>
                </div>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showPanel = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="proposal-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VSlideOver>
    </AppLayout>
</template>
