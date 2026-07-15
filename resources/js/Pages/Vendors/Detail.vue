<script setup>
/**
 * Screen 20 — Vendor Detail, 4 tabs. Información, Contactos, Condiciones
 * de Pago live; Gastos populates in Phase 6.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VTabs from '@/Components/ui/VTabs.vue';

const props = defineProps({
    vendor: { type: Object, required: true },
    contacts: { type: Array, required: true },
    paymentTerms: { type: Array, required: true },
    can: { type: Object, required: true },
});

const tab = ref('info');
const showEdit = ref(false);
const showContact = ref(false);
const showTerm = ref(false);

const tabs = [
    { key: 'info', labelKey: 'vendors.tab_info' },
    { key: 'contacts', labelKey: 'vendors.tab_contacts', count: props.contacts.length },
    { key: 'terms', labelKey: 'vendors.tab_terms', count: props.paymentTerms.length },
    { key: 'expenses', labelKey: 'vendors.tab_expenses' },
];

const infoRows = [
    { k: 'vendors.company_name', v: props.vendor.company_name },
    { k: 'vendors.nif', v: props.vendor.nif },
    { k: 'vendors.phone', v: props.vendor.phone },
    { k: 'vendors.email', v: props.vendor.email },
    { k: 'vendors.address', v: props.vendor.address },
    { k: 'vendors.city', v: props.vendor.city },
    { k: 'vendors.payment_terms', v: props.vendor.payment_terms },
    { k: 'vendors.bank_account', v: props.vendor.bank_account },
];

const editForm = useForm({ ...props.vendor });
function saveEdit() { editForm.put(`/vendors/${props.vendor.id}`, { preserveScroll: true, onSuccess: () => (showEdit.value = false) }); }

const contactForm = useForm({ name: '', position: '', phone: '', email: '', is_primary: false });
function addContact() { contactForm.post(`/vendors/${props.vendor.id}/contacts`, { preserveScroll: true, onSuccess: () => { showContact.value = false; contactForm.reset(); } }); }

const termForm = useForm({ name: '', days: 30, discount_percentage: null, discount_days: null, is_default: false });
function addTerm() { termForm.post(`/vendors/${props.vendor.id}/payment-terms`, { preserveScroll: true, onSuccess: () => { showTerm.value = false; termForm.reset(); } }); }
</script>

<template>
    <Head :title="vendor.name" />
    <AppLayout>
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <VAvatar :name="vendor.name" size="lg" />
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ vendor.name }}</h1>
                <p class="text-sm text-muted">{{ vendor.company_name ?? '—' }}</p>
            </div>
            <VBadge :status="vendor.active ? 'ok' : 'neutral'"><Bilingual :k="vendor.active ? 'vendors.active' : 'employees.inactive'" inline /></VBadge>
            <VButton v-if="can.edit" variant="secondary" icon="edit" @click="showEdit = true"><Bilingual k="vendors.edit" inline /></VButton>
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

            <VCard v-else-if="tab === 'contacts'" :padded="false">
                <div class="flex justify-end p-3"><VButton v-if="can.edit" size="sm" icon="plus" @click="showContact = true"><Bilingual k="vendors.add_contact" inline /></VButton></div>
                <table v-if="contacts.length" class="w-full text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="c in contacts" :key="c.id" class="hover:bg-surface-hover">
                            <td class="px-4 py-2.5 font-medium">{{ c.name }}<VBadge v-if="c.is_primary" status="accent" class="ms-2"><Bilingual k="vendors.is_primary" inline /></VBadge></td>
                            <td class="px-4 py-2.5 text-ink-soft">{{ c.position ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-ink-soft">{{ c.phone ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-ink-soft">{{ c.email ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <VEmptyState v-else icon="user" />
            </VCard>

            <VCard v-else-if="tab === 'terms'" :padded="false">
                <div class="flex justify-end p-3"><VButton v-if="can.edit" size="sm" icon="plus" @click="showTerm = true"><Bilingual k="vendors.add_term" inline /></VButton></div>
                <table v-if="paymentTerms.length" class="w-full text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="t in paymentTerms" :key="t.id" class="hover:bg-surface-hover">
                            <td class="px-4 py-2.5 font-medium">{{ t.name }}<VBadge v-if="t.is_default" status="ok" class="ms-2"><Bilingual k="vendors.is_default" inline /></VBadge></td>
                            <td class="tabular-nums px-4 py-2.5 text-ink-soft">{{ t.days }}d</td>
                            <td class="tabular-nums px-4 py-2.5 text-ink-soft">{{ t.discount_percentage ? t.discount_percentage + '%' : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <VEmptyState v-else icon="invoices" />
            </VCard>

            <VCard v-else-if="tab === 'expenses'">
                <p class="py-8 text-center text-sm text-muted"><Bilingual k="common.coming_soon" class="items-center" /></p>
            </VCard>
        </div>

        <!-- Edit modal -->
        <VModal :open="showEdit" title-key="vendors.edit" @close="showEdit = false">
            <form id="vendor-edit" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveEdit">
                <FormField k="vendors.name" :error="editForm.errors.name" required><VInput v-model="editForm.name" /></FormField>
                <FormField k="vendors.company_name"><VInput v-model="editForm.company_name" /></FormField>
                <FormField k="vendors.nif"><VInput v-model="editForm.nif" /></FormField>
                <FormField k="vendors.phone"><VInput v-model="editForm.phone" /></FormField>
                <FormField k="vendors.email"><VInput v-model="editForm.email" type="email" /></FormField>
                <FormField k="vendors.city"><VInput v-model="editForm.city" /></FormField>
                <VCheckbox v-model="editForm.active"><Bilingual k="vendors.active" inline class="text-sm" /></VCheckbox>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showEdit = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="vendor-edit" :loading="editForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <VModal :open="showContact" title-key="vendors.add_contact" size="sm" @close="showContact = false">
            <form id="vc" class="space-y-3" @submit.prevent="addContact">
                <FormField k="vendors.name" :error="contactForm.errors.name" required><VInput v-model="contactForm.name" /></FormField>
                <FormField k="vendors.position"><VInput v-model="contactForm.position" /></FormField>
                <FormField k="vendors.phone"><VInput v-model="contactForm.phone" /></FormField>
                <FormField k="vendors.email"><VInput v-model="contactForm.email" type="email" /></FormField>
                <VCheckbox v-model="contactForm.is_primary"><Bilingual k="vendors.is_primary" inline class="text-sm" /></VCheckbox>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showContact = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="vc" :loading="contactForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <VModal :open="showTerm" title-key="vendors.add_term" size="sm" @close="showTerm = false">
            <form id="vt" class="space-y-3" @submit.prevent="addTerm">
                <FormField k="vendors.term_name" :error="termForm.errors.name" required><VInput v-model="termForm.name" /></FormField>
                <FormField k="vendors.days" :error="termForm.errors.days" required><VInput v-model="termForm.days" type="number" /></FormField>
                <FormField k="vendors.discount_pct"><VInput v-model="termForm.discount_percentage" type="number" step="0.01" /></FormField>
                <VCheckbox v-model="termForm.is_default"><Bilingual k="vendors.is_default" inline class="text-sm" /></VCheckbox>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showTerm = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="vt" :loading="termForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
