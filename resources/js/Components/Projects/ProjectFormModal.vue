<script setup>
/**
 * Project create/edit modal (Screens 08/09). Company-owned; the client is
 * chosen from the shared pool. VAT is the optional VatRate dropdown.
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useFormDraft } from '@/composables/useFormDraft';
import DraftBanner from '@/Components/ui/DraftBanner.vue';
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
    // Active employees of the acting company — the project's people are chosen
    // from these ({ id, name, designation }).
    employees: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const blank = {
    code: '', client_id: '', name: '', project_type: '', status: 'active', priority: 'medium',
    billing_type: '', vat_rate: null,
    site_manager_id: null, foreman_id: null, safety_id: null, coordinator_id: null,
    start_date: null, end_date: null, budget: null,
    estimated_hours: null, estimated_meters: null, description: '',
    client_hour_rate: null, client_meter_rate: null, outsource_cost: null,
    latitude: null, longitude: null, geofence_radius: 500,
};
const form = useForm({ ...blank });

const draft = useFormDraft(form, { key: () => `project:${props.project?.id ?? 'new'}` });

watch(() => props.open, (open) => {
    if (!open) { draft.disarm(); return; }
    form.clearErrors();
    Object.keys(blank).forEach((k) => { form[k] = props.project?.[k] ?? blank[k]; });
    draft.arm();
});

function submit() {
    const payload = form.transform((d) => ({
        ...d,
        client_id: d.client_id || null,
        billing_type: d.billing_type || null,
        site_manager_id: d.site_manager_id || null,
        foreman_id: d.foreman_id || null,
        safety_id: d.safety_id || null,
        coordinator_id: d.coordinator_id || null,
        latitude: d.latitude === '' ? null : d.latitude,
        longitude: d.longitude === '' ? null : d.longitude,
        geofence_radius: d.geofence_radius === '' || d.geofence_radius === null ? 500 : d.geofence_radius,
    }));
    const opts = { preserveScroll: true, onSuccess: () => { draft.clear(); emit('close'); } };
    props.project ? payload.put(`/projects/${props.project.id}`, opts) : payload.post('/projects', opts);
}

const statuses = ['active', 'in_progress', 'completed', 'cancelled', 'on_hold'];
const priorities = ['low', 'medium', 'high', 'urgent'];
// per_meter retired in favour of task_based (revenue from Production-Task
// progress). Any legacy per_meter project keeps its value; it's just not offered.
const billingTypes = ['fixed', 'hourly', 'task_based', 'milestone'];

// "Name · Designation" for the manager/foreman/safety/coordinator dropdowns.
function employeeLabel(e) {
    return e.designation ? `${e.name} · ${e.designation}` : e.name;
}
</script>

<template>
    <VModal :open="open" :title-key="project ? 'projects.edit' : 'projects.new'" size="lg" @close="emit('close')">
        <form id="project-form" class="space-y-4" @submit.prevent="submit">
            <DraftBanner :show="draft.hasDraft.value" @discard="draft.discard()" />
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField k="projects.code" :error="form.errors.code">
                    <VInput v-model="form.code" :invalid="Boolean(form.errors.code)" :placeholder="$t('projects.code_hint')" />
                </FormField>
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
                <FormField k="projects.jefe_de_obra" :error="form.errors.site_manager_id">
                    <VSelect v-model="form.site_manager_id">
                        <option :value="null">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ employeeLabel(e) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="projects.encargado" :error="form.errors.foreman_id">
                    <VSelect v-model="form.foreman_id">
                        <option :value="null">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ employeeLabel(e) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="projects.seguridad" :error="form.errors.safety_id">
                    <VSelect v-model="form.safety_id">
                        <option :value="null">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ employeeLabel(e) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="projects.coordinator" :error="form.errors.coordinator_id">
                    <VSelect v-model="form.coordinator_id">
                        <option :value="null">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ employeeLabel(e) }}</option>
                    </VSelect>
                </FormField>
            </div>

            <!-- Site location — worker check-in distance verification -->
            <div class="mt-4 mb-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('projects.section_location') }}</p>
                <a href="https://www.google.com/maps" target="_blank" rel="noopener"
                    class="text-xs font-medium text-accent hover:underline">{{ $t('projects.open_google_maps') }} →</a>
            </div>
            <p class="mb-2 text-xs text-muted">{{ $t('projects.location_hint') }}</p>
            <div class="grid gap-4 sm:grid-cols-3">
                <FormField k="projects.latitude" :error="form.errors.latitude">
                    <VInput v-model="form.latitude" type="number" step="0.0000001" placeholder="40.4168" :invalid="Boolean(form.errors.latitude)" />
                </FormField>
                <FormField k="projects.longitude" :error="form.errors.longitude">
                    <VInput v-model="form.longitude" type="number" step="0.0000001" placeholder="-3.7038" :invalid="Boolean(form.errors.longitude)" />
                </FormField>
                <FormField k="projects.geofence_radius" :error="form.errors.geofence_radius">
                    <VInput v-model="form.geofence_radius" type="number" min="50" max="2000" step="50" :invalid="Boolean(form.errors.geofence_radius)" />
                </FormField>
            </div>

            <!-- Rentabilidad: what we bill the client + outsourcing cost -->
            <p class="mt-4 mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ $t('projects.section_profitability') }}</p>
            <div class="grid gap-4 sm:grid-cols-3">
                <FormField k="projects.client_hour_rate" :error="form.errors.client_hour_rate"><VCurrencyInput v-model="form.client_hour_rate" /></FormField>
                <FormField k="projects.client_meter_rate" :error="form.errors.client_meter_rate"><VCurrencyInput v-model="form.client_meter_rate" /></FormField>
                <FormField k="projects.outsource_cost" :error="form.errors.outsource_cost"><VCurrencyInput v-model="form.outsource_cost" /></FormField>
            </div>

            <FormField k="projects.description"><VTextarea v-model="form.description" :rows="2" /></FormField>
        </form>
        <template #footer>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="project-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
        </template>
    </VModal>
</template>
