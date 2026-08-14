<script setup>
/**
 * Screen 07 — Client Detail, 6 tabs. Información, Contactos, Proyectos,
 * Propuestas, Comunicación are live; Facturas populates in Phase 6.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClientFormModal from '@/Components/Clients/ClientFormModal.vue';
import DocumentsPanel from '@/Components/Documents/DocumentsPanel.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VTimeline from '@/Components/ui/VTimeline.vue';
import VTimelineItem from '@/Components/ui/VTimelineItem.vue';
import VFinanceRows from '@/Components/ui/VFinanceRows.vue';

const props = defineProps({
    client: { type: Object, required: true },
    contacts: { type: Array, required: true },
    projects: { type: Array, required: true },
    proposals: { type: Array, required: true },
    communications: { type: Array, required: true },
    invoices: { type: Array, default: () => [] },
    canViewInvoices: { type: Boolean, default: false },
    documents: { type: Array, default: () => [] },
    documentSets: { type: Object, default: () => ({}) },
    documentFieldDefs: { type: Object, default: () => ({}) },
    can: { type: Object, required: true },
});

const tab = ref('info');
const showEdit = ref(false);

const tabs = [
    { key: 'info', labelKey: 'clients.tab_info' },
    { key: 'contacts', labelKey: 'clients.tab_contacts', count: props.contacts.length },
    { key: 'projects', labelKey: 'clients.tab_projects', count: props.projects.length },
    { key: 'proposals', labelKey: 'clients.tab_proposals', count: props.proposals.length },
    { key: 'invoices', labelKey: 'clients.tab_invoices' },
    { key: 'documents', labelKey: 'clients.tab_documents', count: props.documents.length },
    { key: 'communication', labelKey: 'clients.tab_communication', count: props.communications.length },
];

const infoRows = [
    { k: 'clients.company_name', v: props.client.company_name },
    { k: 'clients.nif', v: props.client.nif },
    { k: 'clients.vat_number', v: props.client.vat_number },
    { k: 'clients.contact_person', v: props.client.contact_person },
    { k: 'clients.email', v: props.client.email },
    { k: 'clients.phone', v: props.client.phone },
    { k: 'clients.mobile', v: props.client.mobile },
    { k: 'clients.address', v: props.client.address },
    { k: 'clients.city', v: props.client.city },
    { k: 'clients.payment_terms', v: props.client.payment_terms },
];

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }

const contactForm = useForm({ name: '', designation: '', email: '', phone: '', alternate_phone: '' });
function addContact() {
    contactForm.post(`/clients/${props.client.id}/contacts`, { preserveScroll: true, onSuccess: () => contactForm.reset() });
}
function delContact(contact) {
    askDelete(contact.name ?? '',
        () => router.delete(`/clients/${props.client.id}/contacts/${contact.id}`, { preserveScroll: true }));
}

const commForm = useForm({ type: 'call', body: '', logged_at: null });
function addComm() {
    commForm.post(`/clients/${props.client.id}/communications`, { preserveScroll: true, onSuccess: () => commForm.reset() });
}

const commStatus = { call: 'info', meeting: 'accent', email: 'neutral', note: 'warn' };
const statusBadge = { active: 'ok', in_progress: 'info', completed: 'ok', cancelled: 'danger', on_hold: 'warn',
    draft: 'neutral', sent: 'info', approved: 'ok', rejected: 'danger' };
</script>

<template>
    <Head :title="client.name" />
    <AppLayout>
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <VAvatar :name="client.name" size="lg" />
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ client.name }}</h1>
                <p class="text-sm text-muted">{{ client.company_name ?? '—' }} · {{ client.city ?? '—' }}</p>
            </div>
            <VBadge :status="client.active ? 'ok' : 'neutral'">
                <Bilingual :k="client.active ? 'clients.active' : 'employees.inactive'" inline />
            </VBadge>
            <VButton v-if="can.edit" variant="secondary" icon="edit" @click="showEdit = true">
                <Bilingual k="clients.edit" inline />
            </VButton>
        </div>

        <VTabs v-model="tab" :tabs="tabs" />

        <div class="mt-5">
            <VCard v-if="tab === 'info'">
                <dl class="grid gap-x-8 gap-y-1 sm:grid-cols-2">
                    <div v-for="row in infoRows" :key="row.k" class="flex justify-between gap-4 border-b border-line py-2">
                        <dt><Bilingual :k="row.k" class="text-xs text-muted" /></dt>
                        <dd class="text-end text-sm">{{ row.v ?? '—' }}</dd>
                    </div>
                </dl>
            </VCard>

            <div v-else-if="tab === 'contacts'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard>
                    <VTimeline v-if="contacts.length">
                        <VTimelineItem v-for="c in contacts" :key="c.id" :time="c.designation ?? '—'" :author="c.name">
                            {{ c.email ?? '' }} {{ c.phone ? '· ' + c.phone : '' }}
                            <template v-if="can.edit" #attachment>
                                <button type="button" class="text-xs text-status-danger hover:underline" @click="delContact(c)">
                                    <Bilingual k="documents.delete" inline />
                                </button>
                            </template>
                        </VTimelineItem>
                    </VTimeline>
                    <VEmptyState v-else icon="user" />
                </VCard>
                <VCard v-if="can.edit" title-key="clients.add_contact">
                    <form class="space-y-3" @submit.prevent="addContact">
                        <FormField k="clients.name" :error="contactForm.errors.name" required>
                            <VInput v-model="contactForm.name" />
                        </FormField>
                        <FormField k="clients.designation"><VInput v-model="contactForm.designation" /></FormField>
                        <FormField k="clients.email"><VInput v-model="contactForm.email" type="email" /></FormField>
                        <FormField k="clients.phone"><VInput v-model="contactForm.phone" /></FormField>
                        <VButton type="submit" class="w-full" :loading="contactForm.processing"><Bilingual k="common.save" inline /></VButton>
                    </form>
                </VCard>
            </div>

            <VCard v-else-if="tab === 'projects'" :padded="false">
                <table v-if="projects.length" class="w-full text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="p in projects" :key="p.id" class="cursor-pointer hover:bg-surface-hover" @click="router.get(`/projects/${p.id}`)">
                            <td class="px-4 py-2.5 font-medium">{{ p.name }}</td>
                            <td class="px-4 py-2.5 text-ink-soft">{{ p.company }}</td>
                            <td class="px-4 py-2.5"><VBadge :status="statusBadge[p.status]">{{ p.status }}</VBadge></td>
                            <td class="tabular-nums px-4 py-2.5 text-end">{{ p.budget ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <VEmptyState v-else icon="projects" />
            </VCard>

            <VCard v-else-if="tab === 'proposals'" :padded="false">
                <table v-if="proposals.length" class="w-full text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="p in proposals" :key="p.id" class="hover:bg-surface-hover">
                            <td class="px-4 py-2.5 font-medium">{{ p.number }}</td>
                            <td class="px-4 py-2.5"><VBadge :status="statusBadge[p.status]">{{ p.status }}</VBadge></td>
                            <td class="tabular-nums px-4 py-2.5 text-end">{{ p.total_amount }} €</td>
                        </tr>
                    </tbody>
                </table>
                <VEmptyState v-else icon="file" />
            </VCard>

            <!-- Facturas — this company's invoices to the client -->
            <VFinanceRows v-else-if="tab === 'invoices'"
                :rows="invoices" :can-view="canViewInvoices" empty-key="finance.no_invoices" :show-party="false" />

            <!-- Documentos — this company's paperwork for the shared client -->
            <DocumentsPanel v-else-if="tab === 'documents'" entity-type="client" :entity-id="client.id"
                :documents="documents" :sets="documentSets" :field-defs="documentFieldDefs" :can="can" />

            <div v-else-if="tab === 'communication'" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <VCard>
                    <VTimeline v-if="communications.length">
                        <VTimelineItem v-for="c in communications" :key="c.id" :time="c.logged_at" :author="c.author"
                            :type-label="$t(`clients.comm_${c.type}`)" :type-status="commStatus[c.type]">
                            {{ c.body }}
                        </VTimelineItem>
                    </VTimeline>
                    <VEmptyState v-else icon="calls" />
                </VCard>
                <VCard v-if="can.edit" title-key="clients.add_communication">
                    <form class="space-y-3" @submit.prevent="addComm">
                        <FormField k="clients.tab_communication">
                            <VSelect v-model="commForm.type">
                                <option v-for="t in ['call','meeting','email','note']" :key="t" :value="t">
                                    {{ $t(`clients.comm_${t}`) }}
                                </option>
                            </VSelect>
                        </FormField>
                        <FormField k="clients.notes" :error="commForm.errors.body" required><VTextarea v-model="commForm.body" :rows="3" /></FormField>
                        <VButton type="submit" class="w-full" :loading="commForm.processing"><Bilingual k="common.save" inline /></VButton>
                    </form>
                </VCard>
            </div>
        </div>

        <ClientFormModal :open="showEdit" :client="client" @close="showEdit = false" />
        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
