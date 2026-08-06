<script setup>
/**
 * Screen 21 detail — 6 tabs: Info, Assignments, Maintenance, Fuel, Fines, Mileage.
 * Compliance cards on Info now cover insurance, ITV, AND road tax.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
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
    daily_assignments: { type: Array, required: true },
    fines: { type: Array, required: true },
    fuel_records: { type: Array, required: true },
    sessions: { type: Array, required: true },
    employees: { type: Array, required: true },
    can: { type: Object, required: true },
});

const tab = ref('info');

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }
const tabs = computed(() => [
    { key: 'info', labelKey: 'vehicles.tab_info' },
    { key: 'assignments', labelKey: 'vehicles.tab_assignments', count: props.history.length + props.daily_assignments.length },
    { key: 'maintenance', labelKey: 'vehicles.tab_maintenance', count: props.maintenance.length },
    { key: 'fuel', labelKey: 'vehicles.tab_fuel', count: props.fuel_records.length },
    { key: 'fines', labelKey: 'vehicles.tab_fines', count: props.fines.length },
    { key: 'mileage', labelKey: 'vehicles.tab_mileage', count: props.mileage.length },
    { key: 'sessions', labelKey: 'vehicles.tab_sessions', count: props.sessions.length },
]);

/* ──────────────────────────── Assign (long-term) ───────────────────────── */
const showAssign = ref(false);
const assignForm = useForm({ employee_id: props.vehicle.assigned_employee_id ?? '', notes: '' });
function saveAssign() {
    assignForm.transform((d) => ({ ...d, employee_id: d.employee_id || null }))
        .post(`/vehicles/${props.vehicle.id}/assign`, {
            preserveScroll: true,
            onSuccess: () => (showAssign.value = false),
        });
}

/* ───────────────────────── Daily Assignment ────────────────────────────── */
const showDaily = ref(false);
const dailyForm = useForm({ assigned_date: null, employee_id: '', notes: '' });
function saveDaily() {
    dailyForm.transform((d) => ({ ...d, employee_id: d.employee_id || null }))
        .post(`/vehicles/${props.vehicle.id}/daily-assignments`, {
            preserveScroll: true,
            onSuccess: () => { showDaily.value = false; dailyForm.reset(); },
        });
}
function deleteDaily(d) {
    askDelete(d.assigned_date ?? '',
        () => router.delete(`/vehicles/${props.vehicle.id}/daily-assignments/${d.id}`, { preserveScroll: true }));
}

/* ─────────────────────────── Maintenance ───────────────────────────────── */
const showMaintenance = ref(false);
const maintenanceForm = useForm({
    maintenance_type: '', maintenance_date: null, vehicle_km: null,
    description: '', vendor_name: '', tyre_position: '', cost: null,
});
function saveMaintenance() {
    maintenanceForm.post(`/vehicles/${props.vehicle.id}/maintenance`, {
        preserveScroll: true,
        onSuccess: () => { showMaintenance.value = false; maintenanceForm.reset(); },
    });
}
function deleteMaintenance(m) {
    askDelete(m.maintenance_type ?? '',
        () => router.delete(`/vehicles/${props.vehicle.id}/maintenance/${m.id}`, { preserveScroll: true }));
}

/* ─────────────────────────── Fuel ─────────────────────────────────────── */
const showFuel = ref(false);
const fuelForm = useForm({
    fuel_date: null, litres: null, cost_per_litre: null,
    total_cost: null, mileage_at_fill: null,
    employee_id: '', payment_method: '', notes: '',
});
function saveFuel() {
    fuelForm.transform((d) => ({
        ...d,
        employee_id: d.employee_id || null,
        payment_method: d.payment_method || null,
    })).post(`/vehicles/${props.vehicle.id}/fuel`, {
        preserveScroll: true,
        onSuccess: () => { showFuel.value = false; fuelForm.reset(); },
    });
}
function deleteFuel(r) {
    askDelete(r.fuel_date ?? '',
        () => router.delete(`/vehicles/${props.vehicle.id}/fuel/${r.id}`, { preserveScroll: true }));
}
const fuelPaymentMethods = ['cash', 'card', 'company_card'];
const fuelTotal = computed(() =>
    props.fuel_records.reduce((s, r) => s + (r.total_cost ?? 0), 0).toFixed(2));

/* ─────────────────────────── Fines ────────────────────────────────────── */
const showFine = ref(false);
const fineForm = useForm({
    fine_date: null, amount: null, description: '',
    authority: '', employee_id: '', charged_to: 'company',
});
function saveFine() {
    fineForm.transform((d) => ({
        ...d,
        employee_id: d.employee_id || null,
        authority: d.authority || null,
    })).post(`/vehicles/${props.vehicle.id}/fines`, {
        preserveScroll: true,
        onSuccess: () => { showFine.value = false; fineForm.reset(); fineForm.charged_to = 'company'; },
    });
}
function deleteFine(f) {
    askDelete(f.description ?? '',
        () => router.delete(`/vehicles/${props.vehicle.id}/fines/${f.id}`, { preserveScroll: true }));
}
const finesTotal = computed(() =>
    props.fines.reduce((s, f) => s + (f.amount ?? 0), 0).toFixed(2));

/* ─────────────────────────── Mileage ───────────────────────────────────── */
const showMileage = ref(false);
const mileageForm = useForm({ mileage_value: null, recorded_at: null });
function saveMileage() {
    mileageForm.post(`/vehicles/${props.vehicle.id}/mileage`, {
        preserveScroll: true,
        onSuccess: () => { showMileage.value = false; mileageForm.reset(); },
    });
}

/* ─────────────────────────── Info rows ─────────────────────────────────── */
const infoRows = [
    ['vehicles.vehicle_type', 'vehicle_type_label'],
    ['vehicles.brand', 'brand'],
    ['vehicles.model', 'model'],
    ['vehicles.year', 'year'],
    ['vehicles.color', 'color'],
    ['vehicles.vin_number', 'vin_number'],
    ['vehicles.insurance_policy_number', 'insurance_policy_number'],
    ['vehicles.purchase_date', 'purchase_date'],
    ['vehicles.current_mileage', 'current_mileage'],
    ['vehicles.last_oil_change_mileage', 'last_oil_change_mileage'],
    ['vehicles.last_oil_change_date', 'last_oil_change_date'],
    ['vehicles.oil_change_interval_km', 'oil_change_interval_km'],
    ['vehicles.oil_change_due_at', 'oil_change_due_at'],
    ['vehicles.next_service_date', 'next_service_date'],
    ['vehicles.last_tyre_change_date', 'last_tyre_change_date'],
    ['vehicles.last_tyre_change_mileage', 'last_tyre_change_mileage'],
];

const vehicleRow = computed(() => ({
    ...props.vehicle,
    vehicle_type_label: props.vehicle.vehicle_type
        ? `${t('vehicles.vehicle_type_' + props.vehicle.vehicle_type)}`
        : null,
}));

/* Column configs */
const historyColumns = [
    { key: 'employee', labelKey: 'vehicles.assigned_to' },
    { key: 'from', labelKey: 'vehicles.assigned_from' },
    { key: 'to', labelKey: 'vehicles.assigned_to_date' },
    { key: 'notes', labelKey: 'vehicles.notes' },
];
const dailyColumns = [
    { key: 'date', labelKey: 'vehicles.assigned_date' },
    { key: 'employee', labelKey: 'vehicles.assigned_to' },
    { key: 'notes', labelKey: 'vehicles.notes' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
const maintenanceColumns = [
    { key: 'type', labelKey: 'vehicles.maintenance_type' },
    { key: 'date', labelKey: 'vehicles.maintenance_date' },
    { key: 'km', labelKey: 'vehicles.vehicle_km', align: 'end' },
    { key: 'vendor', labelKey: 'vehicles.vendor_name' },
    { key: 'description', labelKey: 'vehicles.description' },
    { key: 'tyre', labelKey: 'vehicles.tyre_position' },
    { key: 'cost', labelKey: 'vehicles.cost', align: 'end' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
const fuelColumns = [
    { key: 'date', labelKey: 'vehicles.fuel_type' },
    { key: 'litres', labelKey: 'vehicles.litres', align: 'end' },
    { key: 'cpl', labelKey: 'vehicles.cost_per_litre', align: 'end' },
    { key: 'total', labelKey: 'vehicles.total_cost', align: 'end' },
    { key: 'km', labelKey: 'vehicles.mileage_at_fill', align: 'end' },
    { key: 'method', labelKey: 'vehicles.payment_method' },
    { key: 'employee', labelKey: 'vehicles.assigned_to' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
const finesColumns = [
    { key: 'date', labelKey: 'vehicles.fine_date' },
    { key: 'amount', labelKey: 'vehicles.cost', align: 'end' },
    { key: 'description', labelKey: 'vehicles.description' },
    { key: 'authority', labelKey: 'vehicles.authority' },
    { key: 'employee', labelKey: 'vehicles.assigned_to' },
    { key: 'charged', labelKey: 'vehicles.charged_to' },
    { key: 'paid', labelKey: 'vehicles.paid' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
const mileageColumns = [
    { key: 'value', labelKey: 'vehicles.mileage_value', align: 'end' },
    { key: 'recorded_at', labelKey: 'vehicles.recorded_at' },
    { key: 'by', labelKey: 'vehicles.updated_by' },
];
const sessionColumns = [
    { key: 'employee', labelKey: 'employees.title' },
    { key: 'taken_at', labelKey: 'vehicles.session_taken_at' },
    { key: 'returned_at', labelKey: 'vehicles.session_returned_at' },
    { key: 'start', labelKey: 'vehicles.starting_mileage', align: 'end' },
    { key: 'end', labelKey: 'vehicles.ending_mileage', align: 'end' },
    { key: 'km', labelKey: 'vehicles.session_km_driven', align: 'end' },
    { key: 'status', labelKey: 'vehicles.session_status' },
    { key: 'notes', labelKey: 'vehicles.session_notes' },
];
</script>

<template>
    <Head :title="vehicle.plate_number" />
    <AppLayout>
        <!-- Header -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <Link href="/vehicles" class="text-sm text-muted hover:text-ink">&larr;</Link>
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ vehicle.plate_number }}</h1>
                <p class="text-sm text-muted">
                    {{ [vehicle.brand, vehicle.model, vehicle.year].filter(Boolean).join(' · ') || '—' }}
                    <template v-if="vehicle.vehicle_type">
                        · <Bilingual :k="`vehicles.vehicle_type_${vehicle.vehicle_type}`" inline />
                    </template>
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

        <!-- ══════════ Tab: Información ══════════ -->
        <template v-if="tab === 'info'">
            <!-- Cost summary strip -->
            <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <VCard class="text-center">
                    <p class="text-xs uppercase tracking-wide text-muted"><Bilingual k="vehicles.maintenance_cost_total" inline /></p>
                    <p class="tabular-nums mt-1 text-lg font-semibold">{{ vehicle.maintenance_cost_total?.toFixed(2) ?? '—' }} €</p>
                </VCard>
                <VCard class="text-center">
                    <p class="text-xs uppercase tracking-wide text-muted"><Bilingual k="vehicles.fuel_cost_total" inline /></p>
                    <p class="tabular-nums mt-1 text-lg font-semibold">{{ fuelTotal }} €</p>
                </VCard>
                <VCard class="text-center">
                    <p class="text-xs uppercase tracking-wide text-muted"><Bilingual k="vehicles.fine_amount_total" inline /></p>
                    <p class="tabular-nums mt-1 text-lg font-semibold">{{ finesTotal }} €</p>
                </VCard>
            </div>

            <!-- Compliance expiry cards -->
            <div class="grid gap-4 sm:grid-cols-3">
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

            <!-- Detail data -->
            <VCard class="mt-4">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div v-for="[key, field] in infoRows" :key="field" class="flex justify-between gap-3">
                        <dt class="text-sm text-ink-soft"><Bilingual :k="key" inline /></dt>
                        <dd class="tabular-nums text-end text-sm">{{ vehicleRow[field] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-sm text-ink-soft"><Bilingual k="vehicles.maintenance_cost_total" inline /></dt>
                        <dd class="tabular-nums text-end text-sm font-medium">{{ vehicle.maintenance_cost_total }} €</dd>
                    </div>
                </dl>
                <p v-if="vehicle.maintenance_notes" class="mt-4 border-t border-line pt-3 text-sm text-ink-soft">
                    {{ vehicle.maintenance_notes }}
                </p>
            </VCard>
        </template>

        <!-- ══════════ Tab: Asignaciones ══════════ -->
        <template v-else-if="tab === 'assignments'">
            <!-- Long-term assignment history -->
            <div class="mb-6">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-ink-soft uppercase tracking-wide">
                        <Bilingual k="vehicles.long_term_assignments" inline />
                    </h3>
                </div>
                <VTable :columns="historyColumns">
                    <tr v-for="h in history" :key="h.id" class="hover:bg-surface-hover">
                        <td class="px-3 py-2.5 text-sm">{{ h.employee ?? '—' }}</td>
                        <td class="tabular-nums px-3 py-2.5 text-sm">{{ h.assigned_from }}</td>
                        <td class="tabular-nums px-3 py-2.5 text-sm text-ink-soft">{{ h.assigned_to ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-sm text-ink-soft">{{ h.notes ?? '—' }}</td>
                    </tr>
                    <template v-if="history.length === 0" #empty><VEmptyState icon="vehicles" /></template>
                </VTable>
            </div>

            <!-- Daily assignment log -->
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-ink-soft uppercase tracking-wide">
                        <Bilingual k="vehicles.daily_assignments" inline />
                    </h3>
                    <VButton v-if="can.edit" icon="plus" size="sm" @click="showDaily = true">
                        <Bilingual k="vehicles.log_daily_assignment" inline />
                    </VButton>
                </div>
                <VTable :columns="dailyColumns">
                    <tr v-for="d in daily_assignments" :key="d.id" class="hover:bg-surface-hover">
                        <td class="tabular-nums px-3 py-2.5 text-sm">{{ d.assigned_date }}</td>
                        <td class="px-3 py-2.5 text-sm">{{ d.employee ?? $t('vehicles.unassigned') }}</td>
                        <td class="px-3 py-2.5 text-sm text-ink-soft">{{ d.notes ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-end">
                            <VButton v-if="can.edit" variant="ghost" size="sm" icon="trash"
                                @click="deleteDaily(d)" />
                        </td>
                    </tr>
                    <template v-if="daily_assignments.length === 0" #empty>
                        <VEmptyState icon="vehicles" />
                    </template>
                </VTable>
            </div>
        </template>

        <!-- ══════════ Tab: Mantenimiento ══════════ -->
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
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.vendor_name ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.description ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.tyre_position ?? '—' }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ m.cost != null ? m.cost + ' €' : '—' }}</td>
                    <td class="px-3 py-2.5 text-end">
                        <VButton v-if="can.edit" variant="ghost" size="sm" icon="trash" @click="deleteMaintenance(m)" />
                    </td>
                </tr>
                <template v-if="maintenance.length === 0" #empty><VEmptyState icon="vehicles" /></template>
            </VTable>
        </template>

        <!-- ══════════ Tab: Combustible ══════════ -->
        <template v-else-if="tab === 'fuel'">
            <div class="mb-3 flex items-center justify-between">
                <p class="text-sm text-ink-soft">
                    <Bilingual k="vehicles.fuel_cost_total" inline />:
                    <span class="tabular-nums ml-1 font-semibold text-ink">{{ fuelTotal }} €</span>
                </p>
                <VButton v-if="can.edit" icon="plus" size="sm" @click="showFuel = true">
                    <Bilingual k="vehicles.log_fuel" inline />
                </VButton>
            </div>
            <VTable :columns="fuelColumns">
                <tr v-for="r in fuel_records" :key="r.id" class="hover:bg-surface-hover">
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ r.fuel_date }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ r.litres }} L</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ r.cost_per_litre }} €</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm font-medium">{{ r.total_cost }} €</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm text-ink-soft">{{ r.mileage_at_fill ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">
                        <Bilingual v-if="r.payment_method" :k="`vehicles.payment_method_${r.payment_method}`" inline />
                        <span v-else>—</span>
                    </td>
                    <td class="px-3 py-2.5 text-sm">{{ r.employee ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-end">
                        <VButton v-if="can.edit" variant="ghost" size="sm" icon="trash" @click="deleteFuel(r)" />
                    </td>
                </tr>
                <template v-if="fuel_records.length === 0" #empty><VEmptyState icon="vehicles" /></template>
            </VTable>
        </template>

        <!-- ══════════ Tab: Multas ══════════ -->
        <template v-else-if="tab === 'fines'">
            <div class="mb-3 flex items-center justify-between">
                <p class="text-sm text-ink-soft">
                    <Bilingual k="vehicles.fine_amount_total" inline />:
                    <span class="tabular-nums ml-1 font-semibold text-ink">{{ finesTotal }} €</span>
                </p>
                <VButton v-if="can.edit" icon="plus" size="sm" @click="showFine = true">
                    <Bilingual k="vehicles.log_fine" inline />
                </VButton>
            </div>
            <VTable :columns="finesColumns">
                <tr v-for="f in fines" :key="f.id" class="hover:bg-surface-hover">
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ f.fine_date }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm font-medium">{{ f.amount }} €</td>
                    <td class="px-3 py-2.5 text-sm">{{ f.description }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ f.authority ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm">{{ f.employee ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm">
                        <VBadge :status="f.charged_to === 'company' ? 'info' : 'warn'">
                            <Bilingual :k="`vehicles.charged_to_${f.charged_to}`" inline />
                        </VBadge>
                    </td>
                    <td class="px-3 py-2.5 text-sm">
                        <VBadge :status="f.paid ? 'ok' : 'warn'">
                            <Bilingual :k="f.paid ? 'common.yes' : 'common.no'" inline />
                        </VBadge>
                    </td>
                    <td class="px-3 py-2.5 text-end">
                        <VButton v-if="can.edit" variant="ghost" size="sm" icon="trash" @click="deleteFine(f)" />
                    </td>
                </tr>
                <template v-if="fines.length === 0" #empty><VEmptyState icon="vehicles" /></template>
            </VTable>
        </template>

        <!-- ══════════ Tab: Kilometraje ══════════ -->
        <template v-else-if="tab === 'mileage'">
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

        <!-- ══════════ Tab: Sesiones de empleado ══════════ -->
        <template v-else>
            <VTable :columns="sessionColumns">
                <tr v-for="s in sessions" :key="s.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm font-medium">{{ s.employee ?? '—' }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ s.taken_at }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm text-ink-soft">{{ s.returned_at ?? '—' }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ s.starting_mileage }} km</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ s.ending_mileage != null ? s.ending_mileage + ' km' : '—' }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm font-medium">{{ s.km_driven != null ? s.km_driven + ' km' : '—' }}</td>
                    <td class="px-3 py-2.5 text-sm">
                        <VBadge :status="s.open ? 'warn' : 'ok'">
                            <Bilingual :k="s.open ? 'vehicles.session_open' : 'vehicles.session_closed'" inline />
                        </VBadge>
                    </td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ s.return_notes ?? '—' }}</td>
                </tr>
                <template v-if="sessions.length === 0" #empty><VEmptyState icon="vehicles" /></template>
            </VTable>
        </template>

        <!-- ═══════ Modal: Assign (long-term) ═══════ -->
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

        <!-- ═══════ Modal: Daily Assignment ═══════ -->
        <VModal :open="showDaily" title-key="vehicles.log_daily_assignment" size="sm" @close="showDaily = false">
            <form id="daily-form" class="grid gap-4" @submit.prevent="saveDaily">
                <FormField k="vehicles.assigned_date" :error="dailyForm.errors.assigned_date" required>
                    <VDateInput v-model="dailyForm.assigned_date" />
                </FormField>
                <FormField k="vehicles.assigned_to" :error="dailyForm.errors.employee_id">
                    <VSelect v-model="dailyForm.employee_id">
                        <option value="">{{ $t('vehicles.unassigned') }}</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="vehicles.notes" :error="dailyForm.errors.notes">
                    <VTextarea v-model="dailyForm.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showDaily = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="daily-form" :loading="dailyForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- ═══════ Modal: Maintenance ═══════ -->
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
                <FormField k="vehicles.vendor_name" :error="maintenanceForm.errors.vendor_name">
                    <VInput v-model="maintenanceForm.vendor_name" />
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

        <!-- ═══════ Modal: Fuel ═══════ -->
        <VModal :open="showFuel" title-key="vehicles.log_fuel" @close="showFuel = false">
            <form id="fuel-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveFuel">
                <FormField k="vehicles.fuel_date" :error="fuelForm.errors.fuel_date" required class="sm:col-span-2">
                    <VDateInput v-model="fuelForm.fuel_date" />
                </FormField>
                <FormField k="vehicles.litres" :error="fuelForm.errors.litres" required>
                    <VInput v-model="fuelForm.litres" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="vehicles.cost_per_litre" :error="fuelForm.errors.cost_per_litre" required>
                    <VInput v-model="fuelForm.cost_per_litre" type="number" step="0.001" min="0" />
                </FormField>
                <FormField k="vehicles.total_cost" :error="fuelForm.errors.total_cost" required>
                    <VInput v-model="fuelForm.total_cost" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="vehicles.mileage_at_fill" :error="fuelForm.errors.mileage_at_fill">
                    <VInput v-model="fuelForm.mileage_at_fill" type="number" min="0" />
                </FormField>
                <FormField k="vehicles.payment_method" :error="fuelForm.errors.payment_method">
                    <VSelect v-model="fuelForm.payment_method">
                        <option value="">—</option>
                        <option v-for="m in fuelPaymentMethods" :key="m" :value="m">
                            {{ $t(`vehicles.payment_method_${m}`) }}
                        </option>
                    </VSelect>
                </FormField>
                <FormField k="vehicles.assigned_to" :error="fuelForm.errors.employee_id">
                    <VSelect v-model="fuelForm.employee_id">
                        <option value="">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="vehicles.notes" :error="fuelForm.errors.notes" class="sm:col-span-2">
                    <VTextarea v-model="fuelForm.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showFuel = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="fuel-form" :loading="fuelForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- ═══════ Modal: Fine ═══════ -->
        <VModal :open="showFine" title-key="vehicles.log_fine" @close="showFine = false">
            <form id="fine-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveFine">
                <FormField k="vehicles.fine_date" :error="fineForm.errors.fine_date" required>
                    <VDateInput v-model="fineForm.fine_date" />
                </FormField>
                <FormField k="vehicles.cost" :error="fineForm.errors.amount" required>
                    <VInput v-model="fineForm.amount" type="number" step="0.01" min="0.01" />
                </FormField>
                <FormField k="vehicles.description" :error="fineForm.errors.description" required class="sm:col-span-2">
                    <VInput v-model="fineForm.description" />
                </FormField>
                <FormField k="vehicles.authority" :error="fineForm.errors.authority">
                    <VInput v-model="fineForm.authority" />
                </FormField>
                <FormField k="vehicles.charged_to" :error="fineForm.errors.charged_to">
                    <VSelect v-model="fineForm.charged_to">
                        <option value="company">{{ $t('vehicles.charged_to_company') }}</option>
                        <option value="employee">{{ $t('vehicles.charged_to_employee') }}</option>
                    </VSelect>
                </FormField>
                <FormField k="vehicles.assigned_to" :error="fineForm.errors.employee_id" class="sm:col-span-2">
                    <p class="mb-1 text-xs text-muted">
                        <Bilingual k="vehicles.daily_assignments" inline /> — {{ $t('vehicles.fine_date') }}
                    </p>
                    <VSelect v-model="fineForm.employee_id">
                        <option value="">{{ $t('vehicles.unassigned') }}</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showFine = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="fine-form" :loading="fineForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- ═══════ Modal: Mileage ═══════ -->
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

        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
