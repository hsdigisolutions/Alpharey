<script setup>
/**
 * Create / edit a subcontractor (thaekedar). Create posts to /subcontractors
 * (redirects to the detail page); edit puts to /subcontractors/{id}.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    subcontractor: { type: Object, default: null },
    projects: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const blank = {
    name: '', project_id: '', nif: '', phone: '', email: '',
    client_amount: null, agreed_budget: null, expense_responsibility: 'thaekedar',
    start_date: null, end_date: null, status: 'active', notes: '',
};
const form = useForm({ ...blank });

// The deal margin, live: client − budget. Derived display only — the server
// never accepts a posted profit.
const ourProfit = computed(() => {
    const client = Number(form.client_amount);
    const budget = Number(form.agreed_budget);
    if (!client || !budget) return null;
    return Math.round((client - budget) * 100) / 100;
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    Object.keys(blank).forEach((k) => { form[k] = props.subcontractor?.[k] ?? blank[k]; });
});

function submit() {
    const payload = form.transform((d) => ({ ...d, project_id: d.project_id || null }));
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    props.subcontractor ? payload.put(`/subcontractors/${props.subcontractor.id}`, opts) : payload.post('/subcontractors', opts);
}
</script>

<template>
    <VModal :open="open" :title-key="subcontractor ? 'subcontractors.edit' : 'subcontractors.new'" size="lg" @close="emit('close')">
        <form id="subc-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <FormField k="subcontractors.name" :error="form.errors.name" required>
                <VInput v-model="form.name" />
            </FormField>
            <FormField k="subcontractors.project" :error="form.errors.project_id">
                <VSelect v-model="form.project_id">
                    <option value="">—</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </VSelect>
            </FormField>
            <!-- The deal: client amount · thaekedar budget · derived margin. -->
            <FormField k="subcontractors.client_amount" :error="form.errors.client_amount">
                <VCurrencyInput v-model="form.client_amount" />
            </FormField>
            <FormField k="subcontractors.agreed_budget" :error="form.errors.agreed_budget">
                <VCurrencyInput v-model="form.agreed_budget" />
            </FormField>
            <div v-if="ourProfit !== null" class="sm:col-span-2 -mt-1 rounded-md bg-surface-sunken px-3 py-2 text-sm">
                <span class="text-muted">{{ t('subcontractors.our_profit') }}:</span>
                <span class="tabular-nums ms-2 font-semibold" :class="ourProfit >= 0 ? 'text-status-ok' : 'text-status-danger'">
                    {{ ourProfit.toLocaleString('es-ES', { minimumFractionDigits: 2 }) }} €
                </span>
            </div>
            <FormField k="subcontractors.expense_responsibility" class="sm:col-span-2" :error="form.errors.expense_responsibility" required>
                <div class="space-y-1.5">
                    <label class="flex cursor-pointer items-start gap-2 text-sm">
                        <input v-model="form.expense_responsibility" type="radio" value="thaekedar" class="mt-0.5 accent-accent" />
                        <span>{{ t('subcontractors.resp_thaekedar') }}</span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-2 text-sm">
                        <input v-model="form.expense_responsibility" type="radio" value="ours" class="mt-0.5 accent-accent" />
                        <span>{{ t('subcontractors.resp_ours') }}</span>
                    </label>
                </div>
            </FormField>
            <FormField k="subcontractors.nif" :error="form.errors.nif"><VInput v-model="form.nif" /></FormField>
            <FormField k="subcontractors.phone" :error="form.errors.phone"><VInput v-model="form.phone" /></FormField>
            <FormField k="subcontractors.email" :error="form.errors.email"><VInput v-model="form.email" type="email" /></FormField>
            <FormField k="subcontractors.status" :error="form.errors.status" required>
                <VSelect v-model="form.status">
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`subcontractors.status_${s}`) }}</option>
                </VSelect>
            </FormField>
            <FormField k="subcontractors.start_date" :error="form.errors.start_date"><VDateInput v-model="form.start_date" /></FormField>
            <FormField k="subcontractors.end_date" :error="form.errors.end_date"><VDateInput v-model="form.end_date" /></FormField>
            <FormField k="subcontractors.notes" class="sm:col-span-2" :error="form.errors.notes">
                <VTextarea v-model="form.notes" :rows="2" />
            </FormField>
        </form>
        <template #footer>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="subc-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
        </template>
    </VModal>
</template>
