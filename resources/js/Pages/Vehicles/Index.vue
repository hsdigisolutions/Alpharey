<script setup>
/**
 * Screen 21 — Vehicles. The compliance dot on each row is the worst of the
 * vehicle's insurance/ITV expiries, graded server-side by VehicleCompliance
 * on the same traffic light as documents.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
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
    activeSessions: { type: Array, required: true },
    recentSessions: { type: Array, required: true },
    filters: { type: Object, required: true },
    employees: { type: Array, required: true },
    ownerships: { type: Array, required: true },
    fuelTypes: { type: Array, required: true },
    vehicleTypes: { type: Array, required: true },
    can: { type: Object, required: true },
});

/* ---------- live elapsed timer (updates every 30 s) ---------- */
const now = ref(Date.now());
let ticker;
onMounted(() => { ticker = setInterval(() => { now.value = Date.now(); }, 30000); });
onBeforeUnmount(() => clearInterval(ticker));

function toUtc(dt) {
    return /^\d{4}-\d{2}-\d{2} /.test(dt) ? new Date(dt.replace(' ', 'T') + 'Z') : new Date(dt);
}

function elapsed(takenAt) {
    const secs = Math.floor((now.value - toUtc(takenAt)) / 1000);
    const h = Math.floor(secs / 3600);
    const m = Math.floor((secs % 3600) / 60);
    if (h === 0) return `${m}m`;
    return `${h}h ${m > 0 ? m + 'm' : ''}`.trim();
}

function timeOnly(dt) {
    if (!dt) return '—';
    return toUtc(dt).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', hour12: false });
}

function sessionDuration(takenAt, returnedAt) {
    if (!takenAt || !returnedAt) return '—';
    const secs = Math.floor((toUtc(returnedAt) - toUtc(takenAt)) / 1000);
    const d = Math.floor(secs / 86400);
    const h = Math.floor((secs % 86400) / 3600);
    const m = Math.floor((secs % 3600) / 60);
    if (d > 0) return h > 0 ? `${d}d ${h}h` : `${d}d`;
    if (h > 0) return m > 0 ? `${h}h ${m}m` : `${h}h`;
    return `${m}m`;
}

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
    plate_number: '', vehicle_type: '', brand: '', model: '', year: null, ownership: 'company',
    assigned_employee_id: '', active: true, fuel_type: '', color: '',
    vin_number: '', insurance_policy_number: '', insurance_expiry_date: null,
    ita_expiry_date: null, road_tax_expiry_date: null, purchase_date: null, current_mileage: null,
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
        vehicle_type: d.vehicle_type || null,
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

        <!-- ══════════ Active Sessions ══════════ -->
        <section v-if="activeSessions.length" class="mb-8">
            <div class="mb-3 flex items-center gap-2">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-ok opacity-60"></span>
                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-status-ok"></span>
                </span>
                <Bilingual k="vehicles.active_sessions" class="text-[13px] font-semibold text-status-ok" />
                <span class="text-[12px] font-medium text-status-ok opacity-70">({{ activeSessions.length }})</span>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link v-for="s in activeSessions" :key="s.id" :href="`/vehicles/${s.vehicle_id}`"
                    class="group relative overflow-hidden rounded-xl border border-status-ok/30 bg-status-ok-soft p-4 transition hover:border-status-ok/60 hover:shadow-card">
                    <!-- Elapsed badge -->
                    <div class="absolute end-3 top-3 rounded-full bg-status-ok px-2.5 py-0.5 text-[11px] font-bold text-white tabular-nums">
                        {{ elapsed(s.taken_at) }}
                    </div>

                    <!-- Worker name -->
                    <p class="mb-3 text-base font-bold text-status-ok">{{ s.employee ?? '—' }}</p>

                    <!-- Plate badge -->
                    <span class="rounded-md bg-status-ok/15 px-2 py-0.5 font-mono text-[13px] font-bold tracking-wider text-status-ok">
                        {{ s.plate_number }}
                    </span>

                    <!-- Meta row: time + starting km -->
                    <div class="mt-3 flex items-center gap-3 text-[12px] tabular-nums text-status-ok/80">
                        <span class="font-medium">{{ timeOnly(s.taken_at) }}</span>
                        <span class="opacity-40">·</span>
                        <span class="font-medium">{{ s.starting_mileage != null ? s.starting_mileage.toLocaleString() + ' km' : '—' }}</span>
                    </div>
                </Link>
            </div>
        </section>

        <!-- ══════════ Recent Returns ══════════ -->
        <section v-if="recentSessions.length" class="mb-8">
            <div class="mb-3 flex items-center gap-2">
                <AppIcon name="vehicles" class="h-4 w-4 text-muted" />
                <Bilingual k="vehicles.recent_sessions" class="text-[13px] font-semibold text-ink-soft" />
            </div>

            <div class="overflow-hidden rounded-xl border border-line bg-surface-raised">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line bg-surface-sunken text-[11px] font-semibold uppercase tracking-wide text-muted">
                            <th class="px-4 py-2.5 text-start">{{ $t('employees.title') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ $t('vehicles.plate_number') }}</th>
                            <th class="tabular-nums px-4 py-2.5 text-start">{{ $t('vehicles.session_taken_at') }}</th>
                            <th class="tabular-nums px-4 py-2.5 text-start">{{ $t('vehicles.session_returned_at') }}</th>
                            <th class="tabular-nums px-4 py-2.5 text-end">{{ $t('vehicles.session_duration') }}</th>
                            <th class="tabular-nums px-4 py-2.5 text-end">{{ $t('vehicles.session_km_driven') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="s in recentSessions" :key="s.id"
                            class="cursor-pointer transition hover:bg-surface-hover"
                            @click="router.get(`/vehicles/${s.vehicle_id}`)">
                            <td class="px-4 py-2.5 font-medium">{{ s.employee ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded-md bg-surface-sunken px-1.5 py-0.5 font-mono text-[12px] font-semibold">
                                    {{ s.plate_number }}
                                </span>
                            </td>
                            <td class="tabular-nums px-4 py-2.5 text-ink-soft">{{ timeOnly(s.taken_at) }}</td>
                            <td class="tabular-nums px-4 py-2.5 text-ink-soft">{{ timeOnly(s.returned_at) }}</td>
                            <td class="tabular-nums px-4 py-2.5 text-end font-medium text-ink">
                                {{ sessionDuration(s.taken_at, s.returned_at) }}
                            </td>
                            <td class="tabular-nums px-4 py-2.5 text-end font-medium">{{ s.km_driven != null ? s.km_driven + ' km' : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════ Fleet list ══════════ -->
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
                <FormField k="vehicles.vehicle_type" :error="form.errors.vehicle_type">
                    <VSelect v-model="form.vehicle_type">
                        <option value="">—</option>
                        <option v-for="t in vehicleTypes" :key="t" :value="t">{{ $t(`vehicles.vehicle_type_${t}`) }}</option>
                    </VSelect>
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
                <FormField k="vehicles.road_tax_expiry_date" :error="form.errors.road_tax_expiry_date">
                    <VDateInput v-model="form.road_tax_expiry_date" />
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
