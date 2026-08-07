<script setup>
/**
 * Nueva Tarifa Salarial — open a new dated wage rate for an employee.
 * The previous rate is shown read-only; a back-dated start warns that unpaid
 * attendance will be repriced and asks the clerk to confirm before saving.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    employeeId: { type: [Number, String], required: true },
    currentType: { type: String, default: '' },
    currentRate: { type: Object, default: null }, // { wage_type, rate } | null
});

const emit = defineEmits(['close']);

const wageTypes = ['daily', 'hourly', 'monthly', 'per_meter'];

const form = useForm({
    effective_from: null,
    wage_type: '',
    rate: null,
    reason: '',
    confirm_recalculate: false,
});

const showConfirm = ref(false);

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    form.reset();
    form.wage_type = props.currentType || props.currentRate?.wage_type || '';
    showConfirm.value = false;
});

function eur(value) {
    return `${Number(value ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

const previousLabel = computed(() => {
    if (!props.currentRate) return t('wage_rates.no_previous');
    const type = t(`employees.wage_${props.currentRate.wage_type}`);
    return `${eur(props.currentRate.rate)} · ${type}`;
});

function submit() {
    form.post(`/employees/${props.employeeId}/wage-rates`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (errors) => {
            // Server asks to confirm the back-dated recalculation.
            if (errors.confirm_recalculate) {
                showConfirm.value = true;
            }
        },
    });
}

function confirmAndSave() {
    form.confirm_recalculate = true;
    showConfirm.value = false;
    submit();
}
</script>

<template>
    <VModal :open="open" title-key="wage_rates.new_title" size="md" @close="emit('close')">
        <form id="wage-rate-form" class="space-y-4" @submit.prevent="submit">
            <FormField k="wage_rates.effective_from" :error="form.errors.effective_from" required>
                <VDateInput v-model="form.effective_from" />
            </FormField>

            <FormField k="wage_rates.wage_type" :error="form.errors.wage_type" required>
                <VSelect v-model="form.wage_type">
                    <option value="">—</option>
                    <option v-for="type in wageTypes" :key="type" :value="type">
                        {{ t(`employees.wage_${type}`) }}
                    </option>
                </VSelect>
            </FormField>

            <FormField k="wage_rates.rate" :error="form.errors.rate" required>
                <VCurrencyInput v-model="form.rate" />
            </FormField>

            <!-- Tarifa anterior — read-only, auto-filled -->
            <div>
                <span class="mb-1.5 block text-[13px] font-medium">
                    <Bilingual k="wage_rates.previous_rate" />
                </span>
                <div class="tabular-nums rounded-md border border-line-strong bg-surface-sunken px-3 py-2 text-sm text-ink-soft">
                    {{ previousLabel }}
                </div>
            </div>

            <FormField k="wage_rates.reason" :error="form.errors.reason">
                <VInput v-model="form.reason" :placeholder="t('wage_rates.reason_ph')" />
            </FormField>

            <!-- Back-dated confirmation -->
            <div v-if="showConfirm" class="rounded-md border border-status-warn bg-status-warn-soft px-3 py-3 text-sm text-status-warn">
                <p class="mb-3">{{ t('wage_rates.confirm_recalc') }}</p>
                <VButton size="sm" variant="secondary" @click="confirmAndSave">
                    <Bilingual k="wage_rates.confirm_yes" inline />
                </VButton>
            </div>
        </form>

        <template #footer>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="wage-rate-form" :loading="form.processing">
                <Bilingual k="common.save" inline />
            </VButton>
        </template>
    </VModal>
</template>
