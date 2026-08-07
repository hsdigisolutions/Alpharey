<script setup>
/**
 * Add / edit a subcontractor worker. Free-text name; when they are one of our
 * employees, link the record. total_agreed is computed server-side (days × rate);
 * shown live here for reference.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    subcontractorId: { type: [Number, String], required: true },
    worker: { type: Object, default: null },
    employees: { type: Array, default: () => [] },
    payStatuses: { type: Array, default: () => ['pending', 'partial', 'paid'] },
});
const emit = defineEmits(['close']);

const blank = {
    name: '', is_our_employee: false, employee_id: '',
    days_worked: 0, agreed_rate: 0, payment_status: 'pending', notes: '',
};
const form = useForm({ ...blank });

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    Object.keys(blank).forEach((k) => { form[k] = props.worker?.[k] ?? blank[k]; });
});

const liveTotal = computed(() =>
    Math.round((Number(form.days_worked) || 0) * (Number(form.agreed_rate) || 0) * 100) / 100);

function eur(v) {
    return `${Number(v ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

function submit() {
    const payload = form.transform((d) => ({ ...d, employee_id: d.is_our_employee ? (d.employee_id || null) : null }));
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    props.worker
        ? payload.put(`/subcontractors/${props.subcontractorId}/workers/${props.worker.id}`, opts)
        : payload.post(`/subcontractors/${props.subcontractorId}/workers`, opts);
}
</script>

<template>
    <VModal :open="open" :title-key="worker ? 'subcontractors.edit_worker' : 'subcontractors.add_worker'" size="md" @close="emit('close')">
        <form id="worker-form" class="space-y-4" @submit.prevent="submit">
            <FormField k="subcontractors.worker_name" :error="form.errors.name" required>
                <VInput v-model="form.name" />
            </FormField>
            <VCheckbox v-model="form.is_our_employee">
                <Bilingual k="subcontractors.is_our_employee" inline class="text-sm" />
            </VCheckbox>
            <FormField v-if="form.is_our_employee" k="subcontractors.link_employee" :error="form.errors.employee_id" required>
                <VSelect v-model="form.employee_id">
                    <option value="">—</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                </VSelect>
            </FormField>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField k="subcontractors.days_worked" :error="form.errors.days_worked" required>
                    <VInput v-model="form.days_worked" type="number" step="0.5" />
                </FormField>
                <FormField k="subcontractors.agreed_rate" :error="form.errors.agreed_rate" required>
                    <VCurrencyInput v-model="form.agreed_rate" />
                </FormField>
            </div>
            <div class="flex items-center justify-between rounded-md bg-surface-sunken px-3 py-2 text-sm">
                <Bilingual k="subcontractors.total_agreed" inline class="text-ink-soft" />
                <span class="tabular-nums font-semibold">{{ eur(liveTotal) }}</span>
            </div>
            <FormField k="subcontractors.pay_status" :error="form.errors.payment_status" required>
                <VSelect v-model="form.payment_status">
                    <option v-for="s in payStatuses" :key="s" :value="s">{{ t(`subcontractors.pay_${s}`) }}</option>
                </VSelect>
            </FormField>
        </form>
        <template #footer>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="worker-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
        </template>
    </VModal>
</template>
