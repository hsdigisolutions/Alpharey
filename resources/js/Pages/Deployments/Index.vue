<script setup>
/**
 * Screen 12 — Cross-company employee deployments (the signature feature).
 * A host-company admin deploys an employee FROM another company onto one of
 * their own projects. Option A only: the employee stays on the home payroll;
 * the host is cross-charged automatically. Option B (host runs payroll) is
 * cesión ilegal and is never offered here.
 */
import { reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    deployments: { type: Object, required: true },
    filters: { type: Object, required: true },
    homeCompanies: { type: Array, required: true },
    projects: { type: Array, required: true },
    rateTypes: { type: Array, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({ status: props.filters.status ?? '' });
function apply(extra = {}) {
    router.get('/deployments', { ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}

const statusColor = { active: 'info', completed: 'ok', cancelled: 'neutral' };
const railColor = { active: 'bg-status-info', completed: 'bg-status-ok', cancelled: 'bg-status-neutral' };

// Card ↔ Table view toggle (mirrors the Timesheet segmented control). The
// choice is a per-viewer convenience, kept in localStorage (guarded — a private
// window or blocked storage must not break the page).
function readView() {
    try {
        return localStorage.getItem('deployments_view') === 'table' ? 'table' : 'card';
    } catch { return 'card'; }
}
const view = ref(readView());
function setView(v) {
    view.value = v;
    try { localStorage.setItem('deployments_view', v); } catch { /* storage unavailable */ }
}

// ---- Create modal -------------------------------------------------------
const showModal = ref(false);
const availableEmployees = ref([]);
const loadingEmployees = ref(false);
const blank = {
    home_company_id: '', employee_id: '', project_id: '', deployment_start: null,
    deployment_end: null, billing_method: 'option_a', rate_during_deployment: null,
    rate_type: 'hourly', split_pct: 100, notes: '',
};
const form = useForm({ ...blank });

function open() {
    // The host of a deployment is the acting company; pick one first.
    if (!ensureCompanySelected()) return;

    Object.keys(blank).forEach((k) => { form[k] = blank[k]; });
    availableEmployees.value = [];
    form.clearErrors();
    showModal.value = true;
}

async function loadEmployees() {
    form.employee_id = '';
    availableEmployees.value = [];
    if (!form.home_company_id) return;
    loadingEmployees.value = true;
    try {
        const res = await fetch(`/deployments/available-employees?home_company_id=${form.home_company_id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        availableEmployees.value = res.ok ? await res.json() : [];
    } finally {
        loadingEmployees.value = false;
    }
}

function submit() {
    form.post('/deployments', { preserveScroll: true, onSuccess: () => (showModal.value = false) });
}

// --- Edit (ACTIVE deployments only) — employee, rate structure, end date ---
const showEditModal = ref(false);
const editEmployees = ref([]);
const editForm = useForm({
    id: null, employee_id: '', rate_type: 'hourly', rate_during_deployment: null,
    split_pct: 100, deployment_end: null, notes: '',
});
// Context shown read-only in the edit modal (home/host/project/start are fixed).
const editContext = reactive({ home_company: '', host_company: '', project: '', start: '' });

async function openEdit(d) {
    editForm.clearErrors();
    editForm.id = d.id;
    editForm.employee_id = d.employee_id;
    editForm.rate_type = d.rate_type;
    editForm.rate_during_deployment = d.rate;
    editForm.split_pct = d.split_pct;
    editForm.deployment_end = d.end;
    editForm.notes = d.notes ?? '';
    Object.assign(editContext, { home_company: d.home_company, host_company: d.host_company, project: d.project, start: d.start });
    // Always keep the current employee selectable, even if the lookup below
    // is unavailable to an edit-only user.
    editEmployees.value = [{ id: d.employee_id, full_name: d.employee }];
    showEditModal.value = true;
    try {
        const res = await fetch(`/deployments/available-employees?home_company_id=${d.home_company_id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (res.ok) editEmployees.value = await res.json();
    } catch { /* keep the current-employee fallback */ }
}

function submitEdit() {
    editForm.put(`/deployments/${editForm.id}`, { preserveScroll: true, onSuccess: () => (showEditModal.value = false) });
}

function complete(d) { router.post(`/deployments/${d.id}/complete`, {}, { preserveScroll: true }); }
function cancel(d) { router.post(`/deployments/${d.id}/cancel`, {}, { preserveScroll: true }); }
// Host-side settlement: mark the cross-charge paid/unpaid. A pure status write
// — it never touches the internal_deployment expense or project P&L.
function markPaid(d, paid) { router.post(`/deployments/${d.id}/settlement`, { paid }, { preserveScroll: true }); }

function eur(n) {
    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(n));
}
</script>

<template>
    <Head :title="$t('deployments.title')" />
    <AppLayout>
        <VPageHeader k="deployments.title">
            <VButton v-if="can.create" icon="plus" @click="open()"><Bilingual k="deployments.new" inline /></VButton>
        </VPageHeader>

        <div class="flex flex-wrap items-center justify-between gap-2 pb-3">
            <VSelect v-model="filters.status" class="w-full sm:w-52" @update:model-value="apply()">
                <option value="">{{ $t('deployments.all_statuses') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ $t(`deployments.status_${s}`) }}</option>
            </VSelect>

            <div class="inline-flex rounded-lg border border-line bg-surface-sunken p-0.5">
                <button type="button" class="rounded-md px-3 py-1 text-xs font-medium transition"
                    :class="view === 'card' ? 'bg-surface-raised text-ink shadow-card' : 'text-ink-soft hover:text-ink'"
                    @click="setView('card')">{{ $t('deployments.view_card') }}</button>
                <button type="button" class="rounded-md px-3 py-1 text-xs font-medium transition"
                    :class="view === 'table' ? 'bg-surface-raised text-ink shadow-card' : 'text-ink-soft hover:text-ink'"
                    @click="setView('table')">{{ $t('deployments.view_table') }}</button>
            </div>
        </div>

        <template v-if="deployments.data.length">
            <!-- ============================ CARD VIEW ============================ -->
            <div v-if="view === 'card'" class="grid gap-4 lg:grid-cols-2">
                <div v-for="d in deployments.data" :key="d.id"
                    class="group relative flex flex-col overflow-hidden rounded-xl border border-line bg-surface-raised p-5 shadow-card transition hover:shadow-raised">
                    <!-- status-coloured top rail -->
                    <span class="absolute inset-x-0 top-0 h-1" :class="railColor[d.status] ?? 'bg-status-neutral'" />

                    <!-- Header: project + status -->
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[17px] font-semibold leading-tight text-ink">{{ d.project ?? '—' }}</p>
                            <!-- Route chip: HOME sees worker + host; HOST sees only "de {home}". -->
                            <span class="mt-2 inline-flex max-w-full items-center gap-1.5 rounded-full bg-surface-sunken px-2.5 py-1 text-xs text-ink-soft">
                                <template v-if="d.viewer === 'home'">
                                    <span class="truncate font-medium text-ink">{{ d.employee ?? '—' }}</span>
                                    <span class="text-muted">·</span>
                                    <span class="truncate">{{ d.home_company }}</span>
                                    <AppIcon name="chevron-right" class="h-3 w-3 shrink-0 text-muted" />
                                    <span class="truncate">{{ d.host_company }}</span>
                                </template>
                                <template v-else>
                                    <AppIcon name="deployments" class="h-3 w-3 shrink-0 text-muted" />
                                    <span class="truncate">{{ $t('employees.deployed_from', { company: d.home_company }) }}</span>
                                </template>
                            </span>
                        </div>
                        <VBadge :status="statusColor[d.status] ?? 'neutral'" class="shrink-0">
                            <Bilingual :k="`deployments.status_${d.status}`" inline />
                        </VBadge>
                    </div>

                    <!-- Detail -->
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('deployments.period') }}</dt>
                            <dd class="mt-0.5 tabular-nums text-ink">{{ d.start }} → {{ d.end ?? $t('expenses.deployment_ongoing') }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('deployments.days_present') }}</dt>
                            <dd class="mt-0.5 tabular-nums text-ink">{{ d.days_present }}</dd>
                        </div>
                        <template v-if="d.viewer === 'home'">
                            <div>
                                <dt class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('deployments.units') }}</dt>
                                <dd class="mt-0.5 tabular-nums text-ink">{{ d.units }} <Bilingual :k="`deployments.rate_${d.rate_type}`" inline /></dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-medium uppercase tracking-wide text-muted">{{ $t('deployments.rate') }} <span class="normal-case text-muted">({{ $t('deployments.exact_cost') }})</span></dt>
                                <dd class="mt-0.5 tabular-nums text-ink">{{ d.rate ? eur(d.rate) : '—' }}</dd>
                            </div>
                        </template>
                    </dl>

                    <div class="mt-auto pt-4">
                        <!-- HOME: the prominent cross-charge the host owes (receivable) -->
                        <template v-if="d.viewer === 'home'">
                            <div class="flex items-center justify-between rounded-lg border border-accent/30 bg-accent-soft px-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-[11px] font-semibold uppercase tracking-wide text-ink-soft">{{ $t('deployments.owed_by', { company: d.host_company }) }}</p>
                                    <p class="mt-0.5 flex items-center gap-1.5 text-[11px] text-muted">
                                        <span v-if="d.status === 'active'" class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-accent" />
                                        {{ d.status === 'active' ? $t('deployments.owed_live') : $t('deployments.owed_final') }}
                                    </p>
                                </div>
                                <p class="shrink-0 text-[26px] font-semibold tabular-nums leading-none text-accent">{{ eur(d.accrued_cost ?? 0) }}</p>
                            </div>
                            <!-- Receivable settlement status (read-only on the home side) -->
                            <div v-if="d.settlement && d.settlement.is_payable" class="mt-2 flex items-center justify-between px-1 text-[11px]">
                                <span class="text-muted">{{ $t('deployments.receivable') }}<template v-if="d.settlement.invoiced_at"> · {{ d.settlement.invoiced_at }}</template></span>
                                <VBadge :status="d.settlement.status === 'paid' ? 'ok' : 'warn'">
                                    {{ d.settlement.status === 'paid' ? $t('deployments.settled_on', { date: d.settlement.paid_at }) : $t('deployments.pending_payment') }}
                                </VBadge>
                            </div>
                        </template>
                        <!-- HOST: a completed deployment is a payable they can settle;
                             an active one is still accruing (no amount). -->
                        <template v-else>
                            <div v-if="d.settlement && d.settlement.is_payable" class="rounded-lg border border-line bg-surface-sunken px-4 py-3">
                                <div class="flex items-center justify-between">
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-semibold uppercase tracking-wide text-ink-soft">{{ $t('deployments.you_owe', { company: d.home_company }) }}</p>
                                        <p class="mt-0.5 text-[11px] text-muted">{{ d.start }} → {{ d.end ?? $t('expenses.deployment_ongoing') }} · {{ d.days_present }} {{ $t('deployments.days_present').toLowerCase() }}</p>
                                    </div>
                                    <p class="shrink-0 text-2xl font-semibold tabular-nums leading-none text-ink">{{ eur(d.settlement.amount ?? 0) }}</p>
                                </div>
                                <div class="mt-3 flex items-center justify-between gap-2">
                                    <VBadge :status="d.settlement.status === 'paid' ? 'ok' : 'warn'">
                                        {{ d.settlement.status === 'paid' ? $t('deployments.settled_on', { date: d.settlement.paid_at }) : $t('deployments.pending_payment') }}
                                    </VBadge>
                                    <VButton v-if="can.settle && d.settlement.status !== 'paid'" size="sm" icon="check" @click="markPaid(d, true)">
                                        <Bilingual k="deployments.mark_paid" inline />
                                    </VButton>
                                    <VButton v-else-if="can.settle && d.settlement.status === 'paid'" variant="ghost" size="sm" @click="markPaid(d, false)">
                                        <Bilingual k="deployments.mark_unpaid" inline />
                                    </VButton>
                                </div>
                            </div>
                            <p v-else class="rounded-lg bg-surface-sunken px-4 py-3 text-sm text-ink-soft">
                                {{ $t('deployments.host_presence', { days: d.days_present }) }}
                            </p>
                        </template>
                    </div>

                    <!-- Lifecycle actions (manager) -->
                    <div v-if="can.edit && d.status === 'active'" class="mt-4 flex justify-end gap-2 border-t border-line pt-3">
                        <VButton variant="secondary" size="sm" icon="edit" @click="openEdit(d)"><Bilingual k="deployments.edit_action" inline /></VButton>
                        <VButton variant="secondary" size="sm" icon="check" @click="complete(d)"><Bilingual k="deployments.complete" inline /></VButton>
                        <VButton variant="ghost" size="sm" icon="stop" @click="cancel(d)"><Bilingual k="deployments.cancel_action" inline /></VButton>
                    </div>
                </div>
            </div>

            <!-- ============================ TABLE VIEW =========================== -->
            <div v-else class="overflow-x-auto rounded-xl border border-line bg-surface-raised shadow-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line bg-surface-sunken text-[11px] uppercase tracking-wide text-muted">
                            <th class="px-3 py-2.5 text-start font-medium">{{ $t('deployments.employee') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ $t('deployments.route') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ $t('deployments.project') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ $t('deployments.period') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ $t('deployments.days_present') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ $t('deployments.accrued_cost') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ $t('deployments.status') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ $t('deployments.settlement') }}</th>
                            <th v-if="can.edit" class="px-3 py-2.5 text-end font-medium"><span class="sr-only">{{ $t('deployments.edit_action') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="d in deployments.data" :key="d.id" class="hover:bg-surface-hover">
                            <!-- HOME: real worker; HOST: anonymised. -->
                            <td class="px-3 py-2.5 font-medium text-ink">
                                <template v-if="d.viewer === 'home'">{{ d.employee ?? '—' }}</template>
                                <span v-else class="text-muted">{{ $t('deployments.deployed_worker') }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-ink-soft">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="truncate">{{ d.home_company }}</span>
                                    <AppIcon name="chevron-right" class="h-3 w-3 shrink-0 text-muted" />
                                    <span class="truncate">{{ d.host_company }}</span>
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-ink">{{ d.project ?? '—' }}</td>
                            <td class="px-3 py-2.5 tabular-nums text-ink-soft">{{ d.start }} → {{ d.end ?? $t('expenses.deployment_ongoing') }}</td>
                            <td class="px-3 py-2.5 text-end tabular-nums text-ink">{{ d.days_present }}</td>
                            <!-- HOME: exact cost owed (live/locked). HOST: the payable
                                 amount once completed; hidden while accruing. -->
                            <td class="px-3 py-2.5 text-end tabular-nums">
                                <span v-if="d.viewer === 'home'" class="font-semibold text-accent">{{ eur(d.accrued_cost ?? 0) }}</span>
                                <span v-else-if="d.settlement && d.settlement.is_payable" class="font-semibold text-ink">{{ eur(d.settlement.amount ?? 0) }}</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <VBadge :status="statusColor[d.status] ?? 'neutral'">
                                    <Bilingual :k="`deployments.status_${d.status}`" inline />
                                </VBadge>
                            </td>
                            <td class="px-3 py-2.5">
                                <div v-if="d.settlement && d.settlement.is_payable" class="flex items-center gap-2">
                                    <VBadge :status="d.settlement.status === 'paid' ? 'ok' : 'warn'"
                                        :title="d.settlement.status === 'paid' ? (d.settlement.paid_at ?? '') : ''">
                                        {{ d.settlement.status === 'paid' ? $t('deployments.paid_label') : $t('deployments.pending_payment') }}
                                    </VBadge>
                                    <button v-if="d.viewer === 'host' && can.settle && d.settlement.status !== 'paid'" type="button"
                                        class="rounded-sm px-2 py-0.5 text-xs text-accent hover:underline" @click="markPaid(d, true)">
                                        {{ $t('deployments.mark_paid') }}
                                    </button>
                                </div>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td v-if="can.edit" class="px-3 py-2.5 text-end">
                                <span v-if="d.status === 'active'" class="flex items-center justify-end gap-1">
                                    <button type="button" class="rounded-sm p-1.5 text-muted hover:text-ink" :title="$t('deployments.edit_action')" @click="openEdit(d)">
                                        <AppIcon name="edit" class="h-3.5 w-3.5" />
                                    </button>
                                    <button type="button" class="rounded-sm p-1.5 text-muted hover:text-status-ok" :title="$t('deployments.complete')" @click="complete(d)">
                                        <AppIcon name="check" class="h-3.5 w-3.5" />
                                    </button>
                                    <button type="button" class="rounded-sm p-1.5 text-muted hover:text-status-danger" :title="$t('deployments.cancel_action')" @click="cancel(d)">
                                        <AppIcon name="stop" class="h-3.5 w-3.5" />
                                    </button>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
        <VEmptyState v-else icon="deployments" />

        <VPagination :page="deployments.current_page" :pages="deployments.last_page"
            :total="deployments.total" @update:page="(p) => apply({ page: p })" />

        <VModal :open="showModal" title-key="deployments.new" @close="showModal = false">
            <form id="dep-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormField k="deployments.home_company" :error="form.errors.home_company_id" required>
                    <VSelect v-model="form.home_company_id" @update:model-value="loadEmployees">
                        <option value="">—</option>
                        <option v-for="c in homeCompanies" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.employee" :error="form.errors.employee_id" required>
                    <VSelect v-model="form.employee_id" :disabled="!form.home_company_id || loadingEmployees">
                        <option value="">{{ loadingEmployees ? '…' : '—' }}</option>
                        <option v-for="e in availableEmployees" :key="e.id" :value="e.id">
                            {{ e.full_name }}<template v-if="e.designation"> · {{ e.designation }}</template>
                        </option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.project" :error="form.errors.project_id" required>
                    <VSelect v-model="form.project_id">
                        <option value="">—</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.rate_type" :error="form.errors.rate_type" required>
                    <VSelect v-model="form.rate_type">
                        <option v-for="t in rateTypes" :key="t" :value="t">{{ $t(`deployments.rate_${t}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.start" :error="form.errors.deployment_start" required><VDateInput v-model="form.deployment_start" /></FormField>
                <FormField k="deployments.end" :error="form.errors.deployment_end"><VDateInput v-model="form.deployment_end" /></FormField>
                <FormField k="deployments.rate" :error="form.errors.rate_during_deployment"><VInput v-model="form.rate_during_deployment" type="number" step="0.01" min="0" /></FormField>
                <FormField k="deployments.split_pct" :error="form.errors.split_pct"><VInput v-model="form.split_pct" type="number" step="1" min="0" max="100" /></FormField>
                <FormField k="deployments.notes" class="sm:col-span-2"><VTextarea v-model="form.notes" :rows="2" /></FormField>

                <!-- A sentence, so NOT inline: the inline variant is nowrap and
                     would force the modal wider than the viewport. -->
                <p class="sm:col-span-2 rounded-md bg-status-info-soft px-3 py-2 text-xs text-status-info">
                    <Bilingual k="deployments.option_a_note" />
                </p>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="dep-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <!-- Edit (ACTIVE only): employee, rate structure, end date. Home/host/
             project/start are fixed once created and shown read-only. -->
        <VModal :open="showEditModal" title-key="deployments.edit_title" @close="showEditModal = false">
            <form id="dep-edit-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitEdit">
                <div class="sm:col-span-2 rounded-md bg-surface-sunken px-3 py-2 text-xs text-ink-soft">
                    <span class="font-medium">{{ editContext.home_company }} → {{ editContext.host_company }}</span>
                    · {{ editContext.project }} · {{ $t('deployments.start') }}: {{ editContext.start }}
                </div>
                <FormField k="deployments.employee" :error="editForm.errors.employee_id" required>
                    <VSelect v-model="editForm.employee_id">
                        <option v-for="e in editEmployees" :key="e.id" :value="e.id">
                            {{ e.full_name }}<template v-if="e.designation"> · {{ e.designation }}</template>
                        </option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.rate_type" :error="editForm.errors.rate_type" required>
                    <VSelect v-model="editForm.rate_type">
                        <option v-for="t in rateTypes" :key="t" :value="t">{{ $t(`deployments.rate_${t}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="deployments.rate" :error="editForm.errors.rate_during_deployment"><VInput v-model="editForm.rate_during_deployment" type="number" step="0.01" min="0" /></FormField>
                <FormField k="deployments.split_pct" :error="editForm.errors.split_pct"><VInput v-model="editForm.split_pct" type="number" step="1" min="0" max="100" /></FormField>
                <FormField k="deployments.end" :error="editForm.errors.deployment_end"><VDateInput v-model="editForm.deployment_end" /></FormField>
                <FormField k="deployments.notes" class="sm:col-span-2"><VTextarea v-model="editForm.notes" :rows="2" /></FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showEditModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="dep-edit-form" :loading="editForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
