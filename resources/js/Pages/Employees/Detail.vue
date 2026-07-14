<script setup>
/**
 * Screen 06 — Employee Detail. Six tabs; Phase 2 delivers Información,
 * Documentos, Notas, Llamadas. Asistencia + Nómina are placeholders
 * until Phases 4/6. Edit opens the shared modal (never a separate page).
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmployeeFormModal from '@/Components/Employees/EmployeeFormModal.vue';
import DocumentsPanel from '@/Components/Documents/DocumentsPanel.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
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
    can: { type: Object, required: true },
});

const tab = ref('info');
const showEdit = ref(false);
const showDelete = ref(false);

const canSeeWages = props.employee.wage_rate !== null || props.employee.base_salary !== null || props.can.edit;

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
    router.delete(`/employees/${props.employee.id}/notes/${id}`, { preserveScroll: true });
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
    router.delete(`/employees/${props.employee.id}`);
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
                        <VButton variant="danger" size="sm" icon="trash" @click="showDelete = true">
                            <Bilingual k="employees.delete_title" inline />
                        </VButton>
                    </div>
                </VCard>
            </div>

            <!-- Documentos -->
            <DocumentsPanel v-else-if="tab === 'docs'"
                entity-type="employee" :entity-id="employee.id"
                :documents="documents" :sets="documentSets" :can="can" />

            <!-- Asistencia / Nómina placeholders -->
            <VCard v-else-if="tab === 'attendance'">
                <p class="py-8 text-center text-sm text-muted">
                    <Bilingual k="common.coming_soon" class="items-center" />
                </p>
            </VCard>
            <VCard v-else-if="tab === 'payroll'">
                <p class="py-8 text-center text-sm text-muted">
                    <Bilingual k="common.coming_soon" class="items-center" />
                </p>
            </VCard>

            <!-- Notas -->
            <div v-else-if="tab === 'notes'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard>
                    <VTimeline v-if="notes.length">
                        <VTimelineItem v-for="note in notes" :key="note.id"
                            :time="note.noted_at" :author="note.author"
                            :type-label="$page.props.lang.es.employees[`note_${note.type}`]"
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
                                    {{ $page.props.lang.es.employees[`note_${t}`] }} / {{ $page.props.lang.en.employees[`note_${t}`] }}
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

        <VModal :open="showDelete" title-key="employees.delete_title" size="sm" @close="showDelete = false">
            <p class="text-sm text-ink-soft"><Bilingual k="employees.delete_hint" /></p>
            <template #footer>
                <VButton variant="ghost" @click="showDelete = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton variant="danger" @click="destroy"><Bilingual k="employees.delete_title" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
