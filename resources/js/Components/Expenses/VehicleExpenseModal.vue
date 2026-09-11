<script setup>
/**
 * Part D — direct vehicle expense entry (fine / maintenance / fuel) from the
 * Expenses tab. Creates an unapproved Expense that flows through the normal
 * two-gate approval; on final approval the matching vehicle_* row is created.
 * A fine auto-looks-up the driver(s) who had the vehicle on the chosen date.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import FormField from '@/Components/ui/FormField.vue';
import VModal from '@/Components/ui/VModal.vue';
import VInput from '@/Components/ui/VInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VButton from '@/Components/ui/VButton.vue';
import Bilingual from '@/Components/Bilingual.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    vehicles: { type: Array, default: () => [] },
    vendors: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const blank = {
    vehicle_id: '', vehicle_expense_type: 'fine', date: '', amount: null,
    employee_id: '', vendor_id: '', bearable_by: 'company',
    reference: '', litres: null, notes: '',
};
const form = useForm({ ...blank });

// Driver auto-lookup for a fine.
const driverOptions = ref([]);
const driverLoading = ref(false);
const showAllDrivers = ref(false);

async function lookupDrivers() {
    driverOptions.value = [];
    showAllDrivers.value = false;
    if (form.vehicle_expense_type !== 'fine' || !form.vehicle_id || !form.date) return;
    driverLoading.value = true;
    try {
        const res = await fetch(`/vehicles/${form.vehicle_id}/drivers-on-date?date=${encodeURIComponent(form.date)}`, {
            headers: { Accept: 'application/json' },
        });
        if (res.ok) {
            const data = await res.json();
            driverOptions.value = data.drivers ?? [];
            // Pre-select when exactly one driver matches (admin can still override).
            if (driverOptions.value.length === 1) form.employee_id = driverOptions.value[0].id;
        }
    } finally {
        driverLoading.value = false;
    }
}

watch(() => [form.vehicle_id, form.date, form.vehicle_expense_type], lookupDrivers);

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    Object.assign(form, { ...blank });
    driverOptions.value = [];
});

// For a fine with matched drivers, show ONLY the drivers who had the vehicle
// that day (unless the admin opts to see everyone). No match, or a non-fine
// type → the full active list so a driver can still be picked manually.
const usingSessionDrivers = computed(() =>
    form.vehicle_expense_type === 'fine' && driverOptions.value.length > 0 && !showAllDrivers.value);

const employeeChoices = computed(() => usingSessionDrivers.value
    ? driverOptions.value
    : props.employees.map((e) => ({ id: e.id, name: e.full_name })));

function submit() {
    form.transform((d) => ({
        ...d,
        employee_id: d.employee_id || null,
        vendor_id: d.vendor_id || null,
    })).post('/expenses/vehicle', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <VModal :open="open" title-key="expenses.new_vehicle_expense" size="lg" @close="emit('close')">
        <form id="vehicle-expense-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <FormField k="expenses.vehicle" :error="form.errors.vehicle_id" required>
                <VSelect v-model="form.vehicle_id">
                    <option value="">—</option>
                    <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.label }}</option>
                </VSelect>
            </FormField>
            <FormField k="expenses.vehicle_expense_type" :error="form.errors.vehicle_expense_type" required>
                <VSelect v-model="form.vehicle_expense_type">
                    <option value="fine">{{ $t('expenses.vet_fine') }}</option>
                    <option value="maintenance">{{ $t('expenses.vet_maintenance') }}</option>
                    <option value="fuel">{{ $t('expenses.vet_fuel') }}</option>
                </VSelect>
            </FormField>

            <FormField k="expenses.date" :error="form.errors.date" required>
                <VDateInput v-model="form.date" />
            </FormField>
            <FormField k="expenses.amount" :error="form.errors.amount" required>
                <VInput v-model="form.amount" type="number" step="0.01" min="0" />
            </FormField>

            <!-- Fine: reference + driver lookup -->
            <template v-if="form.vehicle_expense_type === 'fine'">
                <FormField k="expenses.fine_reference" :error="form.errors.reference">
                    <VInput v-model="form.reference" />
                </FormField>
                <FormField k="expenses.driver" :error="form.errors.employee_id">
                    <VSelect v-model="form.employee_id">
                        <option value="">{{ driverLoading ? '…' : '—' }}</option>
                        <option v-for="e in employeeChoices" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </VSelect>
                    <p v-if="usingSessionDrivers" class="mt-1 text-[11px] text-muted">
                        {{ $t('expenses.driver_from_sessions') }} ·
                        <button type="button" class="text-accent hover:underline" @click="showAllDrivers = true">
                            {{ $t('expenses.driver_show_all') }}
                        </button>
                    </p>
                </FormField>
            </template>

            <!-- Maintenance: vendor + employee -->
            <template v-else-if="form.vehicle_expense_type === 'maintenance'">
                <FormField k="expenses.vendor" :error="form.errors.vendor_id">
                    <VSelect v-model="form.vendor_id">
                        <option value="">—</option>
                        <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="expenses.employee" :error="form.errors.employee_id">
                    <VSelect v-model="form.employee_id">
                        <option value="">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
            </template>

            <!-- Fuel: litres + employee -->
            <template v-else>
                <FormField k="expenses.litres" :error="form.errors.litres">
                    <VInput v-model="form.litres" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="expenses.employee" :error="form.errors.employee_id">
                    <VSelect v-model="form.employee_id">
                        <option value="">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
            </template>

            <!-- Bearable by (company vs employee) — reuses the standard concept -->
            <FormField k="expenses.bearable_by" :error="form.errors.bearable_by">
                <VSelect v-model="form.bearable_by">
                    <option value="company">{{ $t('expenses.bearable_company') }}</option>
                    <option value="employee">{{ $t('expenses.bearable_employee') }}</option>
                </VSelect>
            </FormField>
            <p class="sm:col-span-2 rounded-md bg-status-info-soft px-3 py-2 text-xs text-status-info">
                {{ $t('expenses.bearable_reimburse_hint') }}
            </p>

            <FormField k="expenses.notes" class="sm:col-span-2" :error="form.errors.notes">
                <VTextarea v-model="form.notes" :rows="2" />
            </FormField>
        </form>
        <template #footer>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="vehicle-expense-form" :loading="form.processing">
                <Bilingual k="common.save" inline />
            </VButton>
        </template>
    </VModal>
</template>
