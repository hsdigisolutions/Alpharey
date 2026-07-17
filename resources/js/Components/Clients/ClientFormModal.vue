<script setup>
/**
 * Client create/edit modal (Screen 07) — never a separate page.
 * Clients are shared across companies; no company field.
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    client: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const blank = {
    name: '', company_name: '', nif: '', vat_number: '', client_type: 'company',
    contact_person: '', phone: '', mobile: '', email: '', address: '', city: '',
    postal_code: '', country: '', website: '', bank_account: '', payment_terms: 'net30',
    industry: '', company_size: '', preferred_contact: '', active: true, notes: '',
};
const form = useForm({ ...blank });

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    Object.keys(blank).forEach((k) => { form[k] = props.client?.[k] ?? blank[k]; });
});

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    props.client ? form.put(`/clients/${props.client.id}`, opts) : form.post('/clients', opts);
}

const types = ['company', 'private', 'municipality', 'other'];
</script>

<template>
    <VModal :open="open" :title-key="client ? 'clients.edit' : 'clients.new'" size="lg" @close="emit('close')">
        <form id="client-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <FormField k="clients.name" :error="form.errors.name" required>
                <VInput v-model="form.name" :invalid="Boolean(form.errors.name)" />
            </FormField>
            <FormField k="clients.company_name" :error="form.errors.company_name">
                <VInput v-model="form.company_name" />
            </FormField>
            <FormField k="clients.type" :error="form.errors.client_type" required>
                <VSelect v-model="form.client_type">
                    <option v-for="t in types" :key="t" :value="t">
                        {{ $t(`clients.type_${t}`) }} / {{ $t(`clients.type_${t}`) }}
                    </option>
                </VSelect>
            </FormField>
            <FormField k="clients.nif" :error="form.errors.nif">
                <VInput v-model="form.nif" />
            </FormField>
            <FormField k="clients.contact_person" :error="form.errors.contact_person">
                <VInput v-model="form.contact_person" />
            </FormField>
            <FormField k="clients.email" :error="form.errors.email">
                <VInput v-model="form.email" type="email" />
            </FormField>
            <FormField k="clients.phone" :error="form.errors.phone">
                <VInput v-model="form.phone" />
            </FormField>
            <FormField k="clients.mobile" :error="form.errors.mobile">
                <VInput v-model="form.mobile" />
            </FormField>
            <FormField k="clients.city" :error="form.errors.city">
                <VInput v-model="form.city" />
            </FormField>
            <FormField k="clients.payment_terms" :error="form.errors.payment_terms">
                <VInput v-model="form.payment_terms" />
            </FormField>
            <FormField k="clients.notes" class="sm:col-span-2" :error="form.errors.notes">
                <VTextarea v-model="form.notes" :rows="2" />
            </FormField>
            <VCheckbox v-model="form.active"><Bilingual k="clients.active" inline class="text-sm" /></VCheckbox>
        </form>
        <template #footer>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="client-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
        </template>
    </VModal>
</template>
