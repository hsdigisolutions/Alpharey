<script setup>
/**
 * Screen 09 — Project Detail, 8 tabs. Phase 3 delivers Resumen, Workers,
 * Documentos, Notas. Asistencia/Mediciones/Facturas/Gastos populate in
 * Phases 4/6. Project notes are IMMUTABLE once saved (no edit/delete).
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectFormModal from '@/Components/Projects/ProjectFormModal.vue';
import DocumentsPanel from '@/Components/Documents/DocumentsPanel.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAlert from '@/Components/ui/VAlert.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VTimeline from '@/Components/ui/VTimeline.vue';
import VTimelineItem from '@/Components/ui/VTimelineItem.vue';
import VFinanceRows from '@/Components/ui/VFinanceRows.vue';

const props = defineProps({
    project: { type: Object, required: true },
    workers: { type: Array, required: true },
    documents: { type: Array, required: true },
    documentSets: { type: Object, required: true },
    remarks: { type: Array, required: true },
    alerts: { type: Array, required: true },
    availableEmployees: { type: Array, required: true },
    clients: { type: Array, required: true },
    vatOptions: { type: Array, required: true },
    invoices: { type: Array, default: () => [] },
    expenses: { type: Array, default: () => [] },
    canViewInvoices: { type: Boolean, default: false },
    canViewExpenses: { type: Boolean, default: false },
    canSeeWages: { type: Boolean, default: false },
    profitability: { type: Object, default: null },
    can: { type: Object, required: true },
});

const tab = ref('summary');
const showEdit = ref(false);

const tabs = [
    { key: 'summary', labelKey: 'projects.tab_summary' },
    { key: 'workers', labelKey: 'projects.tab_workers', count: props.workers.length },
    { key: 'attendance', labelKey: 'projects.tab_attendance' },
    { key: 'measurements', labelKey: 'projects.tab_measurements' },
    { key: 'invoices', labelKey: 'projects.tab_invoices' },
    { key: 'expenses', labelKey: 'projects.tab_expenses' },
    { key: 'documents', labelKey: 'projects.tab_documents', count: props.documents.filter((d) => d.has_file).length },
    { key: 'notes', labelKey: 'projects.tab_notes', count: props.remarks.length },
];

const statusBadge = { active: 'ok', in_progress: 'info', completed: 'ok', cancelled: 'danger', on_hold: 'warn' };
const priorityBadge = { low: 'neutral', medium: 'info', high: 'warn', urgent: 'danger' };
const canSeeWages = props.can.edit;

// Profitability formatting. Margin colour: > 15 % green · 5–15 % amber ·
// < 5 % or negative red — mirrors the server's classification.
function eur(value) {
    if (value === null || value === undefined) return '—';
    return `${Number(value).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}
const marginTone = {
    ok: 'text-status-ok', warn: 'text-status-warn', danger: 'text-status-danger', neutral: 'text-ink-soft',
};

const contactCards = [
    { k: 'projects.jefe_de_obra', name: props.project.jefe_de_obra, phone: props.project.jefe_phone, email: props.project.jefe_email },
    { k: 'projects.encargado', name: props.project.encargado },
    { k: 'projects.seguridad', name: props.project.seguridad },
    { k: 'projects.coordinator', name: props.project.coordinator },
];

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }

// workers
const workerForm = useForm({ employee_id: '', project_rate: null });
function addWorker() { workerForm.post(`/projects/${props.project.id}/workers`, { preserveScroll: true, onSuccess: () => workerForm.reset() }); }
function removeWorker(worker) {
    askDelete(worker.name ?? '',
        () => router.delete(`/projects/${props.project.id}/workers/${worker.id}`, { preserveScroll: true }));
}

// immutable notes
const noteForm = useForm({ type: 'internal', body: '', noted_at: null });
function addNote() { noteForm.post(`/projects/${props.project.id}/remarks`, { preserveScroll: true, onSuccess: () => noteForm.reset() }); }

const noteStatus = { internal: 'neutral', client_call: 'info', client_email: 'accent', meeting: 'warn', message: 'neutral' };

function destroy() {
    askDelete(props.project.name,
        () => router.delete(`/projects/${props.project.id}`));
}
</script>

<template>
    <Head :title="project.name" />
    <AppLayout>
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ project.name }}</h1>
                <p class="tabular-nums text-sm text-muted">{{ project.code }} · {{ project.company }} · {{ project.client ?? '—' }}</p>
            </div>
            <VBadge :status="statusBadge[project.status]"><Bilingual :k="`projects.status_${project.status}`" inline /></VBadge>
            <VBadge :status="priorityBadge[project.priority]"><Bilingual :k="`projects.priority_${project.priority}`" inline /></VBadge>
            <VButton v-if="can.edit" variant="secondary" icon="edit" @click="showEdit = true"><Bilingual k="common.actions" inline /></VButton>
        </div>

        <VTabs v-model="tab" :tabs="tabs" />

        <div class="mt-5">
            <!-- Resumen -->
            <div v-if="tab === 'summary'" class="grid gap-5 lg:grid-cols-2">
                <!-- Rentabilidad (P&L) — gated by the wage right server-side -->
                <VCard v-if="profitability" title-key="profitability.title" class="lg:col-span-2">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <dl class="divide-y divide-line text-sm">
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.client_billing') }}</dt>
                                <dd class="tabular-nums">{{ profitability.client_hour_rate !== null ? `${eur(profitability.client_hour_rate)}/h` : '—' }}</dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.labour_cost') }} <span class="text-muted">{{ $t('profitability.avg') }}</span></dt>
                                <dd class="tabular-nums">{{ profitability.avg_cost_per_hour !== null ? `${eur(profitability.avg_cost_per_hour)}/h` : '—' }}</dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.margin_per_hour') }}</dt>
                                <dd class="tabular-nums font-medium" :class="marginTone[profitability.health]">
                                    {{ profitability.margin_per_hour !== null ? `${eur(profitability.margin_per_hour)}/h` : '—' }}
                                </dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.total_hours') }}</dt>
                                <dd class="tabular-nums">{{ profitability.hours }} h</dd>
                            </div>
                        </dl>
                        <dl class="divide-y divide-line text-sm">
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.total_revenue') }}</dt>
                                <dd class="tabular-nums font-medium">{{ eur(profitability.revenue) }}</dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.labour_cost') }}</dt>
                                <dd class="tabular-nums">{{ eur(profitability.labour_cost) }}</dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.other_expenses') }}</dt>
                                <dd class="tabular-nums">{{ eur(profitability.expenses) }}</dd>
                            </div>
                            <div v-if="profitability.subcontractor_cost > 0" class="flex justify-between py-2">
                                <dt class="text-ink-soft">{{ $t('profitability.subcontractor') }}</dt>
                                <dd class="tabular-nums">{{ eur(profitability.subcontractor_cost) }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface-sunken px-4 py-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-muted">{{ $t('profitability.gross_profit') }}</p>
                            <p class="tabular-nums text-2xl font-semibold" :class="marginTone[profitability.health]">{{ eur(profitability.profit) }}</p>
                        </div>
                        <div class="text-end">
                            <p class="text-xs uppercase tracking-wide text-muted">{{ $t('profitability.margin') }}</p>
                            <p class="tabular-nums text-2xl font-semibold" :class="marginTone[profitability.health]">
                                {{ profitability.margin !== null ? `${profitability.margin.toLocaleString('es-ES')} %` : '—' }}
                            </p>
                        </div>
                    </div>
                    <p v-if="profitability.outsourced" class="mt-3 text-xs text-ink-soft">{{ $t('profitability.outsourced_note') }}</p>
                </VCard>

                <VCard title-key="projects.section_budget">
                    <dl class="divide-y divide-line">
                        <div class="flex justify-between py-2"><dt><Bilingual k="projects.budget" class="text-xs text-muted" /></dt><dd class="tabular-nums text-sm">{{ project.budget ?? '—' }}</dd></div>
                        <div class="flex justify-between py-2"><dt><Bilingual k="projects.billing_type" class="text-xs text-muted" /></dt><dd class="text-sm">{{ project.billing_type ? $t(`projects.billing_${project.billing_type}`) : '—' }}</dd></div>
                        <div class="flex justify-between py-2"><dt><Bilingual k="projects.vat" class="text-xs text-muted" /></dt><dd class="text-sm">{{ project.vat_rate ? $t(`vat.${project.vat_rate}`) : $t('vat.not_applicable') }}</dd></div>
                        <div class="flex justify-between py-2"><dt><Bilingual k="projects.start" class="text-xs text-muted" /></dt><dd class="tabular-nums text-sm">{{ project.start_date ?? '—' }} → {{ project.end_date ?? '—' }}</dd></div>
                    </dl>
                    <p v-if="project.description" class="mt-3 text-sm text-ink-soft">{{ project.description }}</p>
                </VCard>
                <VCard title-key="projects.section_contacts">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div v-for="c in contactCards" :key="c.k" class="rounded-md border border-line p-3">
                            <Bilingual :k="c.k" class="text-xs text-muted" />
                            <p class="mt-0.5 text-sm font-medium">{{ c.name ?? '—' }}</p>
                            <p v-if="c.phone" class="text-xs text-muted">{{ c.phone }}</p>
                            <p v-if="c.email" class="text-xs text-muted">{{ c.email }}</p>
                        </div>
                    </div>
                </VCard>
            </div>

            <!-- Workers -->
            <div v-else-if="tab === 'workers'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard :padded="false">
                    <table v-if="workers.length" class="w-full text-sm">
                        <tbody class="divide-y divide-line">
                            <tr v-for="w in workers" :key="w.id" class="hover:bg-surface-hover">
                                <td class="px-4 py-2.5 font-medium">{{ w.name }}</td>
                                <td class="px-4 py-2.5 text-ink-soft">{{ w.designation ?? '—' }}</td>
                                <td class="tabular-nums px-4 py-2.5 text-ink-soft">{{ canSeeWages ? (w.project_rate ?? '—') : '•••' }}</td>
                                <td class="px-4 py-2.5 text-end">
                                    <button v-if="can.edit" type="button" class="text-xs text-status-danger hover:underline" @click="removeWorker(w)">
                                        <Bilingual k="documents.delete" inline />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <VEmptyState v-else icon="employees" />
                </VCard>
                <VCard v-if="can.edit" title-key="projects.add_worker">
                    <form class="space-y-3" @submit.prevent="addWorker">
                        <FormField k="projects.tab_workers" :error="workerForm.errors.employee_id" required>
                            <VSelect v-model="workerForm.employee_id">
                                <option value="">—</option>
                                <option v-for="e in availableEmployees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                            </VSelect>
                        </FormField>
                        <FormField v-if="canSeeWages" k="projects.project_rate"><VCurrencyInput v-model="workerForm.project_rate" /></FormField>
                        <VButton type="submit" class="w-full" :loading="workerForm.processing"><Bilingual k="common.save" inline /></VButton>
                    </form>
                </VCard>
            </div>

            <!-- Documentos -->
            <DocumentsPanel v-else-if="tab === 'documents'" entity-type="project" :entity-id="project.id"
                :documents="documents" :sets="documentSets" :can="can" />

            <!-- Notas (immutable) -->
            <div v-else-if="tab === 'notes'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard>
                    <VTimeline v-if="remarks.length">
                        <VTimelineItem v-for="r in remarks" :key="r.id" :time="r.noted_at" :author="r.author"
                            :type-label="$t(`projects.note_${r.type}`)" :type-status="noteStatus[r.type]">
                            {{ r.body }}
                        </VTimelineItem>
                    </VTimeline>
                    <VEmptyState v-else icon="file" />
                </VCard>
                <VCard v-if="can.edit" title-key="projects.add_note">
                    <VAlert status="info" class="mb-3"><Bilingual k="projects.note_immutable" /></VAlert>
                    <form class="space-y-3" @submit.prevent="addNote">
                        <FormField k="projects.tab_notes">
                            <VSelect v-model="noteForm.type">
                                <option v-for="t in ['internal','client_call','client_email','meeting','message']" :key="t" :value="t">
                                    {{ $t(`projects.note_${t}`) }}
                                </option>
                            </VSelect>
                        </FormField>
                        <FormField k="projects.description" :error="noteForm.errors.body" required><VTextarea v-model="noteForm.body" :rows="3" /></FormField>
                        <VButton type="submit" class="w-full" :loading="noteForm.processing"><Bilingual k="common.save" inline /></VButton>
                    </form>
                </VCard>
            </div>

            <!-- Facturas — invoices raised against this project -->
            <VFinanceRows v-else-if="tab === 'invoices'"
                :rows="invoices" :can-view="canViewInvoices" empty-key="finance.no_invoices" />

            <!-- Gastos — expenses booked against this project -->
            <VFinanceRows v-else-if="tab === 'expenses'"
                :rows="expenses" :can-view="canViewExpenses" empty-key="finance.no_expenses" />

            <!-- Asistencia / Mediciones — the standalone screens are canonical -->
            <VCard v-else>
                <p class="py-8 text-center text-sm text-muted"><Bilingual k="common.coming_soon" class="items-center" /></p>
            </VCard>
        </div>

        <ProjectFormModal :open="showEdit" :project="project" :clients="clients" :vat-options="vatOptions" @close="showEdit = false" />
        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
