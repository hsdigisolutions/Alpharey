<script setup>
/**
 * Screen 06 — Employee Detail. Six tabs; Phase 2 delivers Información,
 * Documentos, Notas, Llamadas. Asistencia + Nómina are placeholders
 * until Phases 4/6. Edit opens the shared modal (never a separate page).
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmployeeFormModal from '@/Components/Employees/EmployeeFormModal.vue';
import DocumentsPanel from '@/Components/Documents/DocumentsPanel.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VTimeline from '@/Components/ui/VTimeline.vue';
import VTimelineItem from '@/Components/ui/VTimelineItem.vue';

const props = defineProps({
    employee: { type: Object, required: true },
    documents: { type: Array, required: true },
    documentSets: { type: Object, required: true },
    notes: { type: Array, required: true },
    calls: { type: Array, required: true },
    payroll: { type: Array, default: () => [] },
    appAccess: { type: Object, default: () => ({ email: null, active: false }) },
    canSeeWages: { type: Boolean, default: false },
    can: { type: Object, required: true },
});

const tab = ref('info');
const showEdit = ref(false);
const showDelete = ref(false);

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }

// --- Mobile app access (Worker PWA) ---
const showAppAccess = ref(false);

const appAccessForm = useForm({ email: '', password: '' });

function submitAppAccess() {
    appAccessForm.post(`/employees/${props.employee.id}/app-access`, {
        preserveScroll: true,
        onSuccess: () => {
            showAppAccess.value = false;
            appAccessForm.reset();
        },
    });
}

function revokeAppAccess() {
    askDelete(props.appAccess.email ?? props.employee.full_name,
        () => router.delete(`/employees/${props.employee.id}/app-access`, { preserveScroll: true }));
}

// Authoritative from the server (payroll.view || employees.edit), not a guess.
const canSeeWages = props.canSeeWages;

function eur(value) {
    return `${Number(value ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

const infoRows = [
    { k: 'employees.code', v: props.employee.employee_code },
    { k: 'employees.nif', v: props.employee.nif },
    { k: 'auth.email', v: props.employee.email },
    { k: 'employees.mobile', v: props.employee.mobile },
    { k: 'employees.phone', v: props.employee.phone },
    { k: 'employees.city', v: props.employee.city },
    { k: 'employees.address', v: props.employee.address },
    { k: 'employees.department', v: props.employee.department },
    { k: 'employees.designation', v: props.employee.designation },
    { k: 'employees.team_leader', v: props.employee.team_leader },
    { k: 'employees.joining_date', v: props.employee.joining_date },
    { k: 'employees.leaving_date', v: props.employee.leaving_date },
];

const wageRows = [
    { k: 'employees.wage_rate', v: props.employee.wage_rate },
    { k: 'employees.base_salary', v: props.employee.base_salary },
    { k: 'employees.daily_wage', v: props.employee.daily_wage },
    { k: 'employees.per_meter_rate', v: props.employee.per_meter_rate },
    { k: 'employees.commission', v: props.employee.commission_percent },
    { k: 'employees.iban', v: props.employee.iban },
    { k: 'employees.bank_name', v: props.employee.bank_name },
];

const tabs = [
    { key: 'info', labelKey: 'employees.tab_info' },
    { key: 'docs', labelKey: 'employees.tab_docs', count: props.documents.filter((d) => d.has_file || d.has_flag).length },
    { key: 'attendance', labelKey: 'employees.tab_attendance' },
    { key: 'payroll', labelKey: 'employees.tab_payroll' },
    { key: 'notes', labelKey: 'employees.tab_notes', count: props.notes.length },
    { key: 'calls', labelKey: 'employees.tab_calls', count: props.calls.length },
];

const noteStatus = { general: 'neutral', reminder: 'info', issue: 'warn', call: 'accent' };

/* notes */
const noteForm = useForm({ type: 'general', body: '', noted_at: null });
function submitNote() {
    noteForm.post(`/employees/${props.employee.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}
function deleteNote(id) {
    askDelete('', () => router.delete(`/employees/${props.employee.id}/notes/${id}`, { preserveScroll: true }));
}

/* calls */
const callForm = useForm({ called_at: null, remarks: '', follow_up_date: null });
function submitCall() {
    callForm.post(`/employees/${props.employee.id}/calls`, {
        preserveScroll: true,
        onSuccess: () => callForm.reset(),
    });
}

function destroy() {
    askDelete(props.employee.full_name,
        () => router.delete(`/employees/${props.employee.id}`));
}
</script>

<template>
    <Head :title="employee.full_name" />

    <AppLayout>
        <!-- Header -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <VAvatar :name="employee.full_name" size="lg" />
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ employee.full_name }}</h1>
                <p class="tabular-nums text-sm text-muted">
                    {{ employee.employee_code }} · {{ employee.company }} · {{ employee.designation ?? '—' }}
                </p>
            </div>
            <VBadge :status="employee.active ? 'ok' : 'neutral'">
                <Bilingual :k="employee.active ? 'employees.active' : 'employees.inactive'" inline />
            </VBadge>
            <VButton v-if="can.edit" variant="secondary" icon="edit" @click="showEdit = true">
                <Bilingual k="employees.edit" inline />
            </VButton>
        </div>

        <VTabs v-model="tab" :tabs="tabs" />

        <div class="mt-5">
            <!-- Información -->
            <div v-if="tab === 'info'" class="grid gap-5 lg:grid-cols-2">
                <VCard title-key="employees.section_personal">
                    <dl class="divide-y divide-line">
                        <div v-for="row in infoRows" :key="row.k" class="flex justify-between gap-4 py-2">
                            <dt><Bilingual :k="row.k" class="text-xs text-muted" /></dt>
                            <dd class="text-end text-sm">{{ row.v ?? '—' }}</dd>
                        </div>
                    </dl>
                </VCard>
                <VCard v-if="canSeeWages" title-key="employees.section_wage">
                    <dl class="divide-y divide-line">
                        <div v-for="row in wageRows" :key="row.k" class="flex justify-between gap-4 py-2">
                            <dt><Bilingual :k="row.k" class="text-xs text-muted" /></dt>
                            <dd class="tabular-nums text-end text-sm">{{ row.v ?? '—' }}</dd>
                        </div>
                    </dl>
                    <div v-if="can.delete" class="mt-4 border-t border-line pt-4">
                        <VButton variant="danger" size="sm" icon="trash" @click="destroy">
                            <Bilingual k="employees.delete_title" inline />
                        </VButton>
                    </div>
                </VCard>

                <!-- Mobile app access: the login this worker uses to check in
                     on site. Most employees never need one. -->
                <VCard v-if="can.edit" title-key="worker_access.title">
                    <p class="mb-3 text-xs text-muted"><Bilingual k="worker_access.hint" /></p>

                    <div v-if="appAccess.email" class="space-y-3">
                        <div class="flex items-center justify-between gap-3 rounded-md bg-surface-sunken px-3 py-2">
                            <span class="min-w-0 truncate text-sm">{{ appAccess.email }}</span>
                            <VBadge :status="appAccess.active ? 'ok' : 'neutral'">
                                <Bilingual :k="appAccess.active ? 'worker_access.active' : 'worker_access.inactive'" inline />
                            </VBadge>
                        </div>
                        <div class="flex gap-2">
                            <VButton variant="secondary" size="sm" @click="showAppAccess = true">
                                <Bilingual k="worker_access.reset" inline />
                            </VButton>
                            <VButton variant="danger" size="sm" @click="revokeAppAccess">
                                <Bilingual k="worker_access.revoke" inline />
                            </VButton>
                        </div>
                    </div>

                    <VButton v-else size="sm" icon="plus" @click="showAppAccess = true">
                        <Bilingual k="worker_access.grant" inline />
                    </VButton>
                </VCard>
            </div>

            <!-- Documentos -->
            <DocumentsPanel v-else-if="tab === 'docs'"
                entity-type="employee" :entity-id="employee.id"
                :documents="documents" :sets="documentSets" :can="can" />

            <!-- Asistencia placeholder (the standalone grid is canonical) -->
            <VCard v-else-if="tab === 'attendance'">
                <p class="py-8 text-center text-sm text-muted">
                    <Bilingual k="common.coming_soon" class="items-center" />
                </p>
            </VCard>

            <!-- Nómina — the employee's payroll history (pay data, gated) -->
            <VCard v-else-if="tab === 'payroll'">
                <p v-if="!canSeeWages" class="py-8 text-center text-sm text-muted">
                    <Bilingual k="employees.no_wage_permission" class="items-center" />
                </p>
                <p v-else-if="payroll.length === 0" class="py-8 text-center text-sm text-muted">
                    <Bilingual k="employees.no_payroll" class="items-center" />
                </p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase text-muted">
                                <th class="px-2 py-2 text-start font-medium">{{ $t('payroll.month') }}</th>
                                <th class="tabular-nums px-2 py-2 text-end font-medium">{{ $t('payroll.gross') }}</th>
                                <th class="tabular-nums px-2 py-2 text-end font-medium">{{ $t('payroll.net') }}</th>
                                <th class="px-2 py-2 text-start font-medium">{{ $t('payroll.status') }}</th>
                                <th class="px-2 py-2 text-start font-medium">{{ $t('payroll.paid_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in payroll" :key="row.id" class="border-b border-line">
                                <td class="px-2 py-2 text-ink">{{ row.month }}</td>
                                <td class="tabular-nums px-2 py-2 text-end text-ink-soft">{{ eur(row.gross_pay) }}</td>
                                <td class="tabular-nums px-2 py-2 text-end text-ink">{{ eur(row.net_amount) }}</td>
                                <td class="px-2 py-2">
                                    <VBadge :status="row.status === 'paid' ? 'ok' : 'warn'">{{ row.status }}</VBadge>
                                </td>
                                <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.paid_at ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </VCard>

            <!-- Notas -->
            <div v-else-if="tab === 'notes'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard>
                    <VTimeline v-if="notes.length">
                        <VTimelineItem v-for="note in notes" :key="note.id"
                            :time="note.noted_at" :author="note.author"
                            :type-label="$t(`employees.note_${note.type}`)"
                            :type-status="noteStatus[note.type]">
                            {{ note.body }}
                            <template v-if="can.edit" #attachment>
                                <button type="button" class="text-xs text-status-danger hover:underline"
                                    @click="deleteNote(note.id)">
                                    <Bilingual k="documents.delete" inline />
                                </button>
                            </template>
                        </VTimelineItem>
                    </VTimeline>
                    <VEmptyState v-else icon="file" />
                </VCard>
                <VCard v-if="can.edit" title-key="employees.add_note">
                    <form class="space-y-3" @submit.prevent="submitNote">
                        <FormField k="employees.note_type">
                            <VSelect v-model="noteForm.type">
                                <option v-for="t in ['general', 'reminder', 'issue', 'call']" :key="t" :value="t">
                                    {{ $t(`employees.note_${t}`) }}
                                </option>
                            </VSelect>
                        </FormField>
                        <FormField k="employees.notes" :error="noteForm.errors.body" required>
                            <VTextarea v-model="noteForm.body" :rows="3" />
                        </FormField>
                        <VButton type="submit" class="w-full" :loading="noteForm.processing">
                            <Bilingual k="common.save" inline />
                        </VButton>
                    </form>
                </VCard>
            </div>

            <!-- Llamadas -->
            <div v-else-if="tab === 'calls'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard>
                    <VTimeline v-if="calls.length">
                        <VTimelineItem v-for="call in calls" :key="call.id"
                            :time="call.called_at" :author="call.called_by"
                            type-label="Llamada / Call" type-status="accent">
                            {{ call.remarks }}
                            <span v-if="call.follow_up_date" class="mt-1 block text-xs text-status-warn">
                                <Bilingual k="employees.follow_up" inline /> {{ call.follow_up_date }}
                            </span>
                        </VTimelineItem>
                    </VTimeline>
                    <VEmptyState v-else icon="calls" />
                </VCard>
                <VCard title-key="employees.add_call">
                    <form class="space-y-3" @submit.prevent="submitCall">
                        <FormField k="employees.remarks" :error="callForm.errors.remarks" required>
                            <VTextarea v-model="callForm.remarks" :rows="3" />
                        </FormField>
                        <FormField k="employees.follow_up" :error="callForm.errors.follow_up_date">
                            <VDateInput v-model="callForm.follow_up_date" />
                        </FormField>
                        <VButton type="submit" class="w-full" :loading="callForm.processing">
                            <Bilingual k="common.save" inline />
                        </VButton>
                    </form>
                </VCard>
            </div>
        </div>

        <EmployeeFormModal :open="showEdit" :employee="employee" :can-see-wages="canSeeWages" @close="showEdit = false" />

        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />

        <!-- Grant / reset mobile app access -->
        <VModal :open="showAppAccess"
            :title-key="appAccess.email ? 'worker_access.reset' : 'worker_access.grant'"
            size="sm" @close="showAppAccess = false">
            <form id="app-access-form" class="space-y-4" @submit.prevent="submitAppAccess">
                <p class="text-xs text-muted"><Bilingual k="worker_access.modal_hint" /></p>

                <FormField k="auth.email" :error="appAccessForm.errors.email" required>
                    <VInput v-model="appAccessForm.email" type="email" autocomplete="off" />
                </FormField>

                <FormField k="auth.password" :error="appAccessForm.errors.password" required>
                    <VInput v-model="appAccessForm.password" type="text" autocomplete="new-password" />
                </FormField>

                <p class="text-xs text-muted"><Bilingual k="worker_access.password_hint" /></p>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showAppAccess = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="app-access-form" :loading="appAccessForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
