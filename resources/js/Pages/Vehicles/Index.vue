<script setup>
/**
 * Screen 21 — Vehicles. The compliance dot on each row is the worst of the
 * vehicle's insurance/ITV expiries, graded server-side by VehicleCompliance
 * on the same traffic light as documents.
 */
import { reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VStatusDot from '@/Components/ui/VStatusDot.vue';
import VTable from '@/Components/ui/VTable.vue';
import VToggle from '@/Components/ui/VToggle.vue';

const props = defineProps({
    vehicles: { type: Object, required: true },
    filters: { type: Object, required: true },
    employees: { type: Array, required: true },
    ownerships: { type: Array, required: true },
    fuelTypes: { type: Array, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    ownership: props.filters.ownership ?? '',
    per_page: Number(props.filters.per_page ?? 25),
});
function apply(extra = {}) {
    router.get('/vehicles', { ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}

const showModal = ref(false);
const blank = {
    plate_number: '', brand: '', model: '', year: null, ownership: 'company',
    assigned_employee_id: '', active: true, fuel_type: '', color: '',
    vin_number: '', insurance_policy_number: '', insurance_expiry_date: null,
    ita_expiry_date: null, purchase_date: null, current_mileage: null,
};
const form = useForm({ ...blank });

function open() {
    if (!ensureCompanySelected()) return;

    Object.keys(blank).forEach((k) => { form[k] = blank[k]; });
    form.clearErrors();
    showModal.value = true;
}
function submit() {
    form.transform((d) => ({
        ...d,
        assigned_employee_id: d.assigned_employee_id || null,
        fuel_type: d.fuel_type || null,
    })).post('/vehicles', {
        preserveScroll: true,
        onSuccess: () => (showModal.value = false),
    });
}

const columns = [
    { key: 'compliance', labelKey: 'vehicles.compliance' },
    { key: 'plate_number', labelKey: 'vehicles.plate_number' },
    { key: 'brand', labelKey: 'vehicles.brand' },
    { key: 'ownership', labelKey: 'vehicles.ownership' },
    { key: 'assigned', labelKey: 'vehicles.assigned_to' },
    { key: 'fuel', labelKey: 'vehicles.fuel_type' },
    { key: 'ita', labelKey: 'vehicles.ita_expiry_date' },
    { key: 'insurance', labelKey: 'vehicles.insurance_expiry_date' },
    { key: 'active', labelKey: 'vehicles.active' },
];
</script>

<template>
    <Head :title="$t('vehicles.title')" />
    <AppLayout>
        <VPageHeader k="vehicles.title">
            <VButton v-if="can.create" icon="plus" @click="open()"><Bilingual k="vehicles.new" inline /></VButton>
        </VPageHeader>

        <div class="flex flex-wrap items-end gap-2 pb-3">
            <VSearchInput v-model="filters.search" class="w-full sm:w-72" :placeholder="$t('vehicles.search')"
                @update:model-value="apply()" />
            <VSelect v-model="filters.ownership" class="w-full sm:w-48" @update:model-value="apply()">
                <option value="">{{ $t('vehicles.ownership') }}</option>
                <option v-for="o in ownerships" :key="o" :value="o">{{ $t(`vehicles.ownership_${o}`) }}</option>
            </VSelect>
        </div>

        <VTable :columns="columns">
            <tr v-for="v in vehicles.data" :key="v.id" class="cursor-pointer hover:bg-surface-hover"
                @click="router.get(`/vehicles/${v.id}`)">
                <td class="px-3 py-2.5"><VStatusDot :status="v.compliance" /></td>
                <td class="px-3 py-2.5 text-sm font-medium">
                    <Link :href="`/vehicles/${v.id}`" class="hover:text-accent">{{ v.plate_number }}</Link>
                </td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ [v.brand, v.model].filter(Boolean).join(' ') || '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">
                    <Bilingual :k="`vehicles.ownership_${v.ownership}`" inline />
                </td>
                <td class="px-3 py-2.5 text-sm">{{ v.assigned_employee ?? $t('vehicles.unassigned') }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">
                    <Bilingual v-if="v.fuel_type" :k="`vehicles.fuel_${v.fuel_type}`" inline />
                    <span v-else>—</span>
                </td>
                <td class="tabular-nums px-3 py-2.5 text-sm">{{ v.ita_expiry_date ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-sm">{{ v.insurance_expiry_date ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ v.active ? '✓' : '—' }}</td>
            </tr>
            <template v-if="vehicles.data.length === 0" #empty><VEmptyState icon="vehicles" /></template>
        </VTable>

        <VPagination :page="vehicles.current_page" :pages="vehicles.last_page" :per-page="filters.per_page"
            :total="vehicles.total" @update:page="(p) => apply({ page: p })"
            @update:per-page="(pp) => { filters.per_page = pp; apply(); }" />

        <VModal :open="showModal" title-key="vehicles.new" size="lg" @close="showModal = false">
            <form id="vehicle-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormField k="vehicles.plate_number" :error="form.errors.plate_number" required>
                    <VInput v-model="form.plate_number" />
                </FormField>
                <FormField k="vehicles.ownership" :error="form.errors.ownership" required>
                    <VSelect v-model="form.ownership">
                        <option v-for="o in ownerships" :key="o" :value="o">{{ $t(`vehicles.ownership_${o}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="vehicles.brand" :error="form.errors.brand"><VInput v-model="form.brand" /></FormField>
                <FormField k="vehicles.model" :error="form.errors.model"><VInput v-model="form.model" /></FormField>
                <FormField k="vehicles.year" :error="form.errors.year">
                    <VInput v-model="form.year" type="number" />
                </FormField>
                <FormField k="vehicles.fuel_type" :error="form.errors.fuel_type">
                    <VSelect v-model="form.fuel_type">
                        <option value="">—</option>
                        <option v-for="f in fuelTypes" :key="f" :value="f">{{ $t(`vehicles.fuel_${f}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="vehicles.assigned_to" :error="form.errors.assigned_employee_id">
                    <VSelect v-model="form.assigned_employee_id">
                        <option value="">{{ $t('vehicles.unassigned') }}</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="vehicles.color" :error="form.errors.color"><VInput v-model="form.color" /></FormField>
                <FormField k="vehicles.vin_number" :error="form.errors.vin_number">
                    <VInput v-model="form.vin_number" />
                </FormField>
                <FormField k="vehicles.insurance_policy_number" :error="form.errors.insurance_policy_number">
                    <VInput v-model="form.insurance_policy_number" />
                </FormField>
                <FormField k="vehicles.insurance_expiry_date" :error="form.errors.insurance_expiry_date">
                    <VDateInput v-model="form.insurance_expiry_date" />
                </FormField>
                <FormField k="vehicles.ita_expiry_date" :error="form.errors.ita_expiry_date">
                    <VDateInput v-model="form.ita_expiry_date" />
                </FormField>
                <FormField k="vehicles.purchase_date" :error="form.errors.purchase_date">
                    <VDateInput v-model="form.purchase_date" />
                </FormField>
                <FormField k="vehicles.current_mileage" :error="form.errors.current_mileage">
                    <VInput v-model="form.current_mileage" type="number" min="0" />
                </FormField>
                <FormField k="vehicles.active" class="sm:col-span-2"><VToggle v-model="form.active" /></FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="vehicle-form" :loading="form.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
