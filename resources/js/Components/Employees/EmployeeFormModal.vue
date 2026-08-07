<script setup>
/**
 * Screen 05/06 create-edit modal (never a separate page). Sections match
 * the spec: personal / employment / wage / bank / other. Wage + bank
 * fields only render when the server sent them (permission-filtered).
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VInput from '@/Components/ui/VInput.vue';
import VPhoneInput from '@/Components/ui/VPhoneInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    employee: { type: Object, default: null }, // null = create
    canSeeWages: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const blank = {
    full_name: '', nif: '', email: '', mobile: '', phone: '', city: '', address: '',
    department: '', designation: '', joining_date: null, leaving_date: null,
    active: true, is_contracted: false, default_check_in: '09:00', default_check_out: '17:00',
    wage_type: '', wage_rate: null, base_salary: null, daily_wage: null, per_meter_rate: null,
    commission_percent: null, payment_method: '', iban: '', bank_name: '',
    has_driving_license: false, has_company_vehicle: false, can_use_vehicles: false, notes: '',
};

const form = useForm({ ...blank });

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    Object.keys(blank).forEach((key) => {
        form[key] = props.employee?.[key] ?? blank[key];
    });
});

function submit() {
    const payload = form.transform((data) => ({
        ...data,
        wage_type: data.wage_type || null,
        payment_method: data.payment_method || null,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (props.employee) {
        payload.put(`/employees/${props.employee.id}`, options);
    } else {
        payload.post('/employees', options);
    }
}

const wageTypes = ['daily', 'hourly', 'monthly', 'per_meter'];
const paymentMethods = ['bank_transfer', 'cash', 'cash_via_supervisor'];

// Show only the primary wage field for the selected type.
// When no type is selected, show all fields so existing data is visible.
const showWageRate    = computed(() => !form.wage_type || form.wage_type === 'hourly');
const showDailyWage   = computed(() => !form.wage_type || form.wage_type === 'daily');
const showBaseSalary  = computed(() => !form.wage_type || form.wage_type === 'monthly');
const showPerMeter    = computed(() => !form.wage_type || form.wage_type === 'per_meter');

// Bank section only needed for bank transfer.
const showBank = computed(() => !form.payment_method || form.payment_method === 'bank_transfer');
</script>

<template>
    <VModal :open="open" :title-key="employee ? 'employees.edit' : 'employees.new'" size="lg" @close="emit('close')">
        <form id="employee-form" class="space-y-6" @submit.prevent="submit">
            <!-- Personal -->
            <section>
                <Bilingual k="employees.section_personal" class="mb-3 text-[15px] font-semibold" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="employees.full_name" :error="form.errors.full_name" required>
                        <VInput v-model="form.full_name" :invalid="Boolean(form.errors.full_name)" />
                    </FormField>
                    <FormField k="employees.nif" :error="form.errors.nif">
                        <VInput v-model="form.nif" />
                    </FormField>
                    <FormField k="auth.email" :error="form.errors.email">
                        <VInput v-model="form.email" type="email" />
                    </FormField>
                    <FormField k="employees.mobile" :error="form.errors.mobile">
                        <VPhoneInput v-model="form.mobile" />
                    </FormField>
                    <FormField k="employees.city" :error="form.errors.city">
                        <VInput v-model="form.city" />
                    </FormField>
                    <FormField k="employees.address" :error="form.errors.address">
                        <VInput v-model="form.address" />
                    </FormField>
                </div>
            </section>

            <!-- Employment -->
            <section>
                <Bilingual k="employees.section_employment" class="mb-3 text-[15px] font-semibold" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="employees.department" :error="form.errors.department">
                        <VInput v-model="form.department" />
                    </FormField>
                    <FormField k="employees.designation" :error="form.errors.designation">
                        <VInput v-model="form.designation" />
                    </FormField>
                    <FormField k="employees.joining_date" :error="form.errors.joining_date">
                        <VDateInput v-model="form.joining_date" />
                    </FormField>
                    <FormField k="employees.leaving_date" :error="form.errors.leaving_date">
                        <VDateInput v-model="form.leaving_date" />
                    </FormField>
                    <FormField k="employees.check_in" :error="form.errors.default_check_in">
                        <VDateInput v-model="form.default_check_in" type="time" />
                    </FormField>
                    <FormField k="employees.check_out" :error="form.errors.default_check_out">
                        <VDateInput v-model="form.default_check_out" type="time" />
                    </FormField>
                </div>
                <div class="mt-3 flex flex-wrap gap-6">
                    <VCheckbox v-model="form.active"><Bilingual k="employees.active" inline class="text-sm" /></VCheckbox>
                    <VCheckbox v-model="form.is_contracted"><Bilingual k="employees.is_contracted" inline class="text-sm" /></VCheckbox>
                    <VCheckbox v-model="form.can_use_vehicles"><Bilingual k="employees.can_use_vehicles" inline class="text-sm" /></VCheckbox>
                </div>
            </section>

            <!-- Wage (permission-gated) -->
            <section v-if="canSeeWages">
                <Bilingual k="employees.section_wage" class="mb-3 text-[15px] font-semibold" />
                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField k="employees.wage_type" :error="form.errors.wage_type">
                        <VSelect v-model="form.wage_type">
                            <option value="">—</option>
                            <option v-for="type in wageTypes" :key="type" :value="type">
                                {{ $t(`employees.wage_${type}`) }}
                            </option>
                        </VSelect>
                    </FormField>
                    <FormField v-if="showWageRate" k="employees.wage_rate" :error="form.errors.wage_rate">
                        <VCurrencyInput v-model="form.wage_rate" />
                    </FormField>
                    <FormField v-if="showBaseSalary" k="employees.base_salary" :error="form.errors.base_salary">
                        <VCurrencyInput v-model="form.base_salary" />
                    </FormField>
                    <FormField v-if="showDailyWage" k="employees.daily_wage" :error="form.errors.daily_wage">
                        <VCurrencyInput v-model="form.daily_wage" />
                    </FormField>
                    <FormField v-if="showPerMeter" k="employees.per_meter_rate" :error="form.errors.per_meter_rate">
                        <VCurrencyInput v-model="form.per_meter_rate" />
                    </FormField>
                    <FormField k="employees.commission" :error="form.errors.commission_percent">
                        <VInput v-model="form.commission_percent" type="number" step="0.01" />
                    </FormField>
                    <FormField k="employees.payment_method" :error="form.errors.payment_method">
                        <VSelect v-model="form.payment_method">
                            <option value="">—</option>
                            <option v-for="method in paymentMethods" :key="method" :value="method">
                                {{ $t(`employees.pm_${method}`) }}
                            </option>
                        </VSelect>
                    </FormField>
                </div>
            </section>

            <!-- Bank (permission-gated; hidden for cash payments) -->
            <section v-if="canSeeWages && showBank">
                <Bilingual k="employees.section_bank" class="mb-3 text-[15px] font-semibold" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="employees.iban" :error="form.errors.iban">
                        <VInput v-model="form.iban" />
                    </FormField>
                    <FormField k="employees.bank_name" :error="form.errors.bank_name">
                        <VInput v-model="form.bank_name" />
                    </FormField>
                </div>
            </section>

            <!-- Other -->
            <section>
                <Bilingual k="employees.section_other" class="mb-3 text-[15px] font-semibold" />
                <div class="flex flex-wrap gap-6">
                    <VCheckbox v-model="form.has_driving_license"><Bilingual k="employees.driving_license" inline class="text-sm" /></VCheckbox>
                    <VCheckbox v-model="form.has_company_vehicle"><Bilingual k="employees.company_vehicle" inline class="text-sm" /></VCheckbox>
                </div>
                <FormField k="employees.notes" class="mt-3" :error="form.errors.notes">
                    <VTextarea v-model="form.notes" :rows="2" />
                </FormField>
            </section>
        </form>

        <template #footer>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="employee-form" :loading="form.processing">
                <Bilingual k="common.save" inline />
            </VButton>
        </template>
    </VModal>
</template>
