<script setup>
/**
 * Project create/edit modal (Screens 08/09). Company-owned; the client is
 * chosen from the shared pool. VAT is the optional VatRate dropdown.
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VVatSelect from '@/Components/ui/VVatSelect.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    project: { type: Object, default: null },
    clients: { type: Array, required: true },
    vatOptions: { type: Array, required: true },
});
const emit = defineEmits(['close']);

const blank = {
    client_id: '', name: '', project_type: '', status: 'active', priority: 'medium',
    billing_type: '', vat_rate: null, jefe_de_obra: '', encargado: '', seguridad: '',
    coordinator: '', start_date: null, end_date: null, budget: null,
    estimated_hours: null, estimated_meters: null, description: '',
};
const form = useForm({ ...blank });

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    Object.keys(blank).forEach((k) => { form[k] = props.project?.[k] ?? blank[k]; });
});

function submit() {
    const payload = form.transform((d) => ({ ...d, client_id: d.client_id || null, billing_type: d.billing_type || null }));
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    props.project ? payload.put(`/projects/${props.project.id}`, opts) : payload.post('/projects', opts);
}

const statuses = ['active', 'in_progress', 'completed', 'cancelled', 'on_hold'];
const priorities = ['low', 'medium', 'high', 'urgent'];
const billingTypes = ['fixed', 'hourly', 'per_meter', 'milestone'];
</script>

<template>
    <VModal :open="open" :title-key="project ? 'projects.edit' : 'projects.new'" size="lg" @close="emit('close')">
        <form id="project-form" class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField k="projects.name" :error="form.errors.name" required>
                    <VInput v-model="form.name" :invalid="Boolean(form.errors.name)" />
                </FormField>
                <FormField k="projects.client" :error="form.errors.client_id">
                    <VSelect v-model="form.client_id">
                        <option value="">—</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="projects.type"><VInput v-model="form.project_type" /></FormField>
                <FormField k="projects.billing_type">
                    <VSelect v-model="form.billing_type">
                        <option value="">—</option>
                        <option v-for="b in billingTypes" :key="b" :value="b">{{ $t(`projects.billing_${b}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="projects.status" required>
                    <VSelect v-model="form.status">
                        <option v-for="s in statuses" :key="s" :value="s">{{ $t(`projects.status_${s}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="projects.priority" required>
                    <VSelect v-model="form.priority">
                        <option v-for="p in priorities" :key="p" :value="p">{{ $t(`projects.priority_${p}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="projects.start"><VDateInput v-model="form.start_date" /></FormField>
                <FormField k="projects.end" :error="form.errors.end_date"><VDateInput v-model="form.end_date" /></FormField>
                <FormField k="projects.budget"><VCurrencyInput v-model="form.budget" /></FormField>
                <FormField k="projects.vat"><VVatSelect v-model="form.vat_rate" :options="vatOptions" /></FormField>
                <FormField k="projects.jefe_de_obra"><VInput v-model="form.jefe_de_obra" /></FormField>
                <FormField k="projects.encargado"><VInput v-model="form.encargado" /></FormField>
            </div>
            <FormField k="projects.description"><VTextarea v-model="form.description" :rows="2" /></FormField>
        </form>
        <template #footer>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="project-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
        </template>
    </VModal>
</template>
