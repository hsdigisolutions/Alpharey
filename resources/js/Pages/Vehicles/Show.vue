<script setup>
/**
 * Screen 21 detail — the 4 tabs. The expiry cards on Información carry the
 * compliance traffic light: an unknown date reads 'neutral', not 'ok'.
 */
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VStatusDot from '@/Components/ui/VStatusDot.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    vehicle: { type: Object, required: true },
    expiries: { type: Object, required: true },
    history: { type: Array, required: true },
    maintenance: { type: Array, required: true },
    mileage: { type: Array, required: true },
    employees: { type: Array, required: true },
    can: { type: Object, required: true },
});

const tab = ref('info');
const tabs = [
    { key: 'info', labelKey: 'vehicles.tab_info' },
    { key: 'history', labelKey: 'vehicles.tab_history', count: props.history.length },
    { key: 'maintenance', labelKey: 'vehicles.tab_maintenance', count: props.maintenance.length },
    { key: 'mileage', labelKey: 'vehicles.tab_mileage', count: props.mileage.length },
];

/* Assign */
const showAssign = ref(false);
const assignForm = useForm({ employee_id: props.vehicle.assigned_employee_id ?? '', notes: '' });
function saveAssign() {
    assignForm.transform((d) => ({ ...d, employee_id: d.employee_id || null }))
        .post(`/vehicles/${props.vehicle.id}/assign`, {
            preserveScroll: true,
            onSuccess: () => (showAssign.value = false),
        });
}

/* Maintenance */
const showMaintenance = ref(false);
const maintenanceForm = useForm({
    maintenance_type: '', maintenance_date: null, vehicle_km: null,
    description: '', tyre_position: '', cost: null,
});
function saveMaintenance() {
    maintenanceForm.post(`/vehicles/${props.vehicle.id}/maintenance`, {
        preserveScroll: true,
        onSuccess: () => { showMaintenance.value = false; maintenanceForm.reset(); },
    });
}
function deleteMaintenance(m) {
    router.delete(`/vehicles/${props.vehicle.id}/maintenance/${m.id}`, { preserveScroll: true });
}

/* Mileage */
const showMileage = ref(false);
const mileageForm = useForm({ mileage_value: null, recorded_at: null });
function saveMileage() {
    mileageForm.post(`/vehicles/${props.vehicle.id}/mileage`, {
        preserveScroll: true,
        onSuccess: () => { showMileage.value = false; mileageForm.reset(); },
    });
}

const infoRows = [
    ['vehicles.brand', 'brand'], ['vehicles.model', 'model'], ['vehicles.year', 'year'],
    ['vehicles.color', 'color'], ['vehicles.vin_number', 'vin_number'],
    ['vehicles.insurance_policy_number', 'insurance_policy_number'],
    ['vehicles.purchase_date', 'purchase_date'], ['vehicles.current_mileage', 'current_mileage'],
    ['vehicles.last_oil_change_mileage', 'last_oil_change_mileage'],
    ['vehicles.last_oil_change_date', 'last_oil_change_date'],
    ['vehicles.oil_change_interval_km', 'oil_change_interval_km'],
    ['vehicles.oil_change_due_at', 'oil_change_due_at'],
    ['vehicles.next_service_date', 'next_service_date'],
    ['vehicles.last_tyre_change_date', 'last_tyre_change_date'],
    ['vehicles.last_tyre_change_mileage', 'last_tyre_change_mileage'],
];

const historyColumns = [
    { key: 'employee', labelKey: 'vehicles.assigned_to' },
    { key: 'from', labelKey: 'vehicles.assigned_from' },
    { key: 'to', labelKey: 'vehicles.assigned_to_date' },
    { key: 'notes', labelKey: 'vehicles.notes' },
];
const maintenanceColumns = [
    { key: 'type', labelKey: 'vehicles.maintenance_type' },
    { key: 'date', labelKey: 'vehicles.maintenance_date' },
    { key: 'km', labelKey: 'vehicles.vehicle_km', align: 'end' },
    { key: 'description', labelKey: 'vehicles.description' },
    { key: 'tyre', labelKey: 'vehicles.tyre_position' },
    { key: 'cost', labelKey: 'vehicles.cost', align: 'end' },
    { key: 'by', labelKey: 'vehicles.created_by' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
const mileageColumns = [
    { key: 'value', labelKey: 'vehicles.mileage_value', align: 'end' },
    { key: 'recorded_at', labelKey: 'vehicles.recorded_at' },
    { key: 'by', labelKey: 'vehicles.updated_by' },
];
</script>

<template>
    <Head :title="vehicle.plate_number" />
    <AppLayout>
        <!-- Header (the detail-page pattern: plain h1, the record names itself) -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <Link href="/vehicles" class="text-sm text-muted hover:text-ink">&larr;</Link>
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ vehicle.plate_number }}</h1>
                <p class="text-sm text-muted">
                    {{ [vehicle.brand, vehicle.model, vehicle.year].filter(Boolean).join(' · ') || '—' }}
                </p>
            </div>
            <VBadge :status="vehicle.active ? 'ok' : 'neutral'">
                <Bilingual :k="vehicle.active ? 'vehicles.active' : 'vehicles.inactive'" inline />
            </VBadge>
            <VButton v-if="can.edit" variant="secondary" @click="showAssign = true">
                <Bilingual k="vehicles.assign" inline />
            </VButton>
        </div>

        <VTabs v-model="tab" :tabs="tabs" class="mb-4" />

        <!-- Información -->
        <template v-if="tab === 'info'">
            <div class="grid gap-4 sm:grid-cols-2">
                <VCard v-for="(expiry, field) in expiries" :key="field">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <VStatusDot :status="expiry.status" />
                            <Bilingual :k="`vehicles.${field}_expiry_date`" class="text-sm font-medium" />
                        </div>
                        <div class="text-end">
                            <p class="tabular-nums text-sm">{{ expiry.date ?? '—' }}</p>
                            <p v-if="expiry.days !== null" class="text-xs text-muted">
                                {{ expiry.days }} {{ $t('vehicles.days_left') }}
                            </p>
                        </div>
                    </div>
                </VCard>
            </div>

            <VCard class="mt-4">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div v-for="[key, field] in infoRows" :key="field" class="flex justify-between gap-3">
                        <dt class="text-sm text-ink-soft"><Bilingual :k="key" inline /></dt>
                        <dd class="tabular-nums text-end text-sm">{{ vehicle[field] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-sm text-ink-soft"><Bilingual k="vehicles.maintenance_cost_total" inline /></dt>
                        <dd class="tabular-nums text-end text-sm font-medium">{{ vehicle.maintenance_cost_total }}</dd>
                    </div>
                </dl>
                <p v-if="vehicle.maintenance_notes" class="mt-4 border-t border-line pt-3 text-sm text-ink-soft">
                    {{ vehicle.maintenance_notes }}
                </p>
            </VCard>
        </template>

        <!-- Historial de Asignación -->
        <template v-else-if="tab === 'history'">
            <VTable :columns="historyColumns">
                <tr v-for="h in history" :key="h.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm">{{ h.employee ?? '—' }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ h.assigned_from }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm text-ink-soft">{{ h.assigned_to ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ h.notes ?? '—' }}</td>
                </tr>
                <template v-if="history.length === 0" #empty><VEmptyState icon="vehicles" /></template>
            </VTable>
        </template>

        <!-- Mantenimiento -->
        <template v-else-if="tab === 'maintenance'">
            <div class="flex justify-end pb-3">
                <VButton v-if="can.edit" icon="plus" size="sm" @click="showMaintenance = true">
                    <Bilingual k="vehicles.log_maintenance" inline />
                </VButton>
            </div>
            <VTable :columns="maintenanceColumns">
                <tr v-for="m in maintenance" :key="m.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm">{{ m.maintenance_type }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ m.maintenance_date }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ m.vehicle_km ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.description ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.tyre_position ?? '—' }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ m.cost ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.created_by ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-end">
                        <VButton v-if="can.edit" variant="ghost" size="sm" icon="trash" @click="deleteMaintenance(m)" />
                    </td>
                </tr>
                <template v-if="maintenance.length === 0" #empty><VEmptyState icon="vehicles" /></template>
            </VTable>
        </template>

        <!-- Kilometraje -->
        <template v-else>
            <div class="flex justify-end pb-3">
                <VButton v-if="can.edit" icon="plus" size="sm" @click="showMileage = true">
                    <Bilingual k="vehicles.log_mileage" inline />
                </VButton>
            </div>
            <VTable :columns="mileageColumns">
                <tr v-for="m in mileage" :key="m.id" class="hover:bg-surface-hover">
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ m.mileage_value }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ m.recorded_at }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.updated_by ?? '—' }}</td>
                </tr>
                <template v-if="mileage.length === 0" #empty><VEmptyState icon="vehicles" /></template>
            </VTable>
        </template>

        <!-- Assign -->
        <VModal :open="showAssign" title-key="vehicles.assign" size="sm" @close="showAssign = false">
            <form id="assign-form" class="grid gap-4" @submit.prevent="saveAssign">
                <FormField k="vehicles.assigned_to" :error="assignForm.errors.employee_id">
                    <VSelect v-model="assignForm.employee_id">
                        <option value="">{{ $t('vehicles.unassigned') }}</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="vehicles.notes" :error="assignForm.errors.notes">
                    <VTextarea v-model="assignForm.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showAssign = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="assign-form" :loading="assignForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Log maintenance -->
        <VModal :open="showMaintenance" title-key="vehicles.log_maintenance" @close="showMaintenance = false">
            <form id="maint-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveMaintenance">
                <FormField k="vehicles.maintenance_type" :error="maintenanceForm.errors.maintenance_type" required>
                    <VInput v-model="maintenanceForm.maintenance_type" />
                </FormField>
                <FormField k="vehicles.maintenance_date" :error="maintenanceForm.errors.maintenance_date" required>
                    <VDateInput v-model="maintenanceForm.maintenance_date" />
                </FormField>
                <FormField k="vehicles.vehicle_km" :error="maintenanceForm.errors.vehicle_km">
                    <VInput v-model="maintenanceForm.vehicle_km" type="number" min="0" />
                </FormField>
                <FormField k="vehicles.cost" :error="maintenanceForm.errors.cost">
                    <VInput v-model="maintenanceForm.cost" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="vehicles.tyre_position" :error="maintenanceForm.errors.tyre_position">
                    <VInput v-model="maintenanceForm.tyre_position" />
                </FormField>
                <FormField k="vehicles.description" :error="maintenanceForm.errors.description" class="sm:col-span-2">
                    <VTextarea v-model="maintenanceForm.description" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showMaintenance = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="maint-form" :loading="maintenanceForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Log mileage -->
        <VModal :open="showMileage" title-key="vehicles.log_mileage" size="sm" @close="showMileage = false">
            <form id="mileage-form" class="grid gap-4" @submit.prevent="saveMileage">
                <FormField k="vehicles.mileage_value" :error="mileageForm.errors.mileage_value" required>
                    <VInput v-model="mileageForm.mileage_value" type="number" min="0" />
                </FormField>
                <FormField k="vehicles.recorded_at" :error="mileageForm.errors.recorded_at">
                    <VDateInput v-model="mileageForm.recorded_at" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showMileage = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="mileage-form" :loading="mileageForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
