<script setup>
/**
 * Screen 05 — Employees List: server-driven sort/filter/live search,
 * column visibility saved per user, bulk activate/deactivate, 25/50/100
 * pagination, Excel/PDF export of the current filtered view, Excel
 * import with template.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { ensureCompanySelected } from '@/composables/useCompanyGate';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmployeeFormModal from '@/Components/Employees/EmployeeFormModal.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VBulkBar from '@/Components/ui/VBulkBar.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VDropdown from '@/Components/ui/VDropdown.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VFileDrop from '@/Components/ui/VFileDrop.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VStatusDot from '@/Components/ui/VStatusDot.vue';
import VTable from '@/Components/ui/VTable.vue';

const props = defineProps({
    employees: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
    visibleColumns: { type: Array, default: null },
    canSeeWages: { type: Boolean, required: true },
    stats: { type: Object, default: () => ({ total: 0, active: 0, inactive: 0 }) },
    can: { type: Object, required: true },
});

// Summary cards double as the status filter.
function setStatus(val) {
    filters.status = val;
    apply();
}

/* ---------- filters (server-driven) ---------- */
const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    department: props.filters.department ?? '',
    designation: props.filters.designation ?? '',
    wage_type: props.filters.wage_type ?? '',
    sort: props.filters.sort ?? 'full_name',
    dir: props.filters.dir ?? 'asc',
    per_page: Number(props.filters.per_page ?? 25),
});

let searchTimer = null;

watch(() => filters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => apply(), 350); // live search
});

function apply(extra = {}) {
    router.get('/employees', { ...filters, ...extra }, {
        preserveScroll: true,
        preserveState: true,
    });
}

function sortBy(key) {
    filters.dir = filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc';
    filters.sort = key;
    apply();
}

/* ---------- columns (saved per user, §10) ---------- */
const allColumns = computed(() => [
    { key: 'employee_code', labelKey: 'employees.code', sortable: true },
    { key: 'full_name', labelKey: 'employees.full_name', sortable: true },
    { key: 'company', labelKey: 'employees.company' },
    { key: 'department', labelKey: 'employees.department', sortable: true },
    { key: 'designation', labelKey: 'employees.designation', sortable: true },
    { key: 'city', labelKey: 'employees.city' },
    { key: 'mobile', labelKey: 'employees.mobile' },
    { key: 'wage_type', labelKey: 'employees.wage_type' },
    ...(props.canSeeWages ? [
        // The rate matching the worker's own wage type (not just hourly).
        { key: 'wage_rate', labelKey: 'employees.rate', align: 'end' },
        { key: 'base_salary', labelKey: 'employees.base_salary', align: 'end' },
        { key: 'commission_percent', labelKey: 'employees.commission', align: 'end' },
    ] : []),
    { key: 'active', labelKey: 'employees.status' },
    { key: 'doc_status', labelKey: 'employees.doc_status' },
]);

const defaultVisible = ['employee_code', 'full_name', 'designation', 'wage_type', 'wage_rate', 'base_salary', 'active', 'doc_status'];
const visible = ref(new Set(props.visibleColumns ?? defaultVisible));

const columns = computed(() => allColumns.value.filter((column) => visible.value.has(column.key)));

function toggleColumn(key) {
    visible.value.has(key) ? visible.value.delete(key) : visible.value.add(key);
    visible.value = new Set(visible.value);
    router.put('/column-settings', {
        table_name: 'employees',
        visible_columns: [...visible.value],
    }, { preserveScroll: true, preserveState: true });
}

/* ---------- selection + bulk ---------- */
const selected = ref(new Set());

function toggleRow(id) {
    selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id);
    selected.value = new Set(selected.value);
}

function toggleAll(checked) {
    selected.value = new Set(checked ? props.employees.data.map((e) => e.id) : []);
}

function bulkActive(active) {
    router.post('/employees/bulk-active', { ids: [...selected.value], active }, {
        preserveScroll: true,
        onSuccess: () => (selected.value = new Set()),
    });
}

/* ---------- export / import / create ---------- */
function exportAs(format) {
    const params = new URLSearchParams(
        Object.entries({ ...filters, format }).filter(([, v]) => v !== '' && v !== null),
    );
    window.location.href = `/employees/export?${params.toString()}`;
}

const showImport = ref(false);
const importing = ref(false);

function importFile(files) {
    importing.value = true;
    router.post('/employees/import', { file: files[0] }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            importing.value = false;
            showImport.value = false;
        },
    });
}

const showForm = ref(false);

function openCreate() {
    // A new employee joins the acting company; a company-less Super Admin
    // picks one first (otherwise the store would have no company to file into).
    if (!ensureCompanySelected()) return;
    showForm.value = true;
}

const docDot = { ok: 'ok', warn: 'warn', danger: 'danger', neutral: 'neutral', exempt: 'neutral' };
</script>

<template>
    <Head :title="$t('employees.title')" />

    <AppLayout>
        <VPageHeader k="employees.title">
            <!-- Secondary action collapses to icon-only on small screens so the
                 two wide bilingual buttons never wrap into a ragged stack. -->
            <VButton v-if="can.create" variant="secondary" icon="upload"
                :aria-label="$tPair('employees.import')"
                @click="showImport = true">
                <span class="hidden sm:inline"><Bilingual k="employees.import" inline /></span>
            </VButton>
            <VButton v-if="can.create" icon="plus" @click="openCreate">
                <Bilingual k="employees.new" inline />
            </VButton>
        </VPageHeader>

        <!-- Summary cards — server counts; each is a shortcut to the status
             filter (Total = all · Active · Inactive). The selected one is ringed. -->
        <div class="grid grid-cols-3 gap-3 pb-3">
            <button type="button" @click="setStatus('')"
                class="rounded-lg border bg-surface-raised p-4 text-start shadow-card transition hover:bg-surface-hover"
                :class="filters.status === '' ? 'border-accent ring-1 ring-accent' : 'border-line'">
                <Bilingual k="employees.stat_total" class="text-xs font-medium text-ink-soft" inline />
                <p class="tabular-nums mt-1 text-2xl font-semibold text-ink">{{ stats.total }}</p>
                <Bilingual k="employees.title" class="text-xs text-muted" inline />
            </button>
            <button type="button" @click="setStatus('active')"
                class="rounded-lg border bg-surface-raised p-4 text-start shadow-card transition hover:bg-surface-hover"
                :class="filters.status === 'active' ? 'border-status-ok ring-1 ring-status-ok' : 'border-line'">
                <Bilingual k="employees.active" class="text-xs font-medium text-status-ok" inline />
                <p class="tabular-nums mt-1 text-2xl font-semibold text-status-ok">{{ stats.active }}</p>
                <Bilingual k="employees.title" class="text-xs text-muted" inline />
            </button>
            <button type="button" @click="setStatus('inactive')"
                class="rounded-lg border bg-surface-raised p-4 text-start shadow-card transition hover:bg-surface-hover"
                :class="filters.status === 'inactive' ? 'border-status-warn ring-1 ring-status-warn' : 'border-line'">
                <Bilingual k="employees.inactive" class="text-xs font-medium text-status-warn" inline />
                <p class="tabular-nums mt-1 text-2xl font-semibold text-status-warn">{{ stats.inactive }}</p>
                <Bilingual k="employees.title" class="text-xs text-muted" inline />
            </button>
        </div>

        <!-- Toolbar: search + utilities on one row, filters on an even grid
             below. Filters are grid-sized (never fixed widths) so the long
             bilingual placeholders can't clip. -->
        <div class="space-y-2 pb-3">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div class="sm:w-64">
                    <VSearchInput v-model="filters.search" />
                </div>

                <div class="flex flex-wrap items-center gap-2 sm:ms-auto">
                    <VDropdown width="w-56">
                        <template #trigger="{ toggle }">
                            <VButton variant="secondary" size="sm" icon="columns" @click="toggle">
                                <Bilingual k="employees.columns" inline />
                            </VButton>
                        </template>
                        <div class="max-h-72 space-y-1 overflow-y-auto p-1.5">
                            <label v-for="column in allColumns" :key="column.key" class="flex items-center gap-2 rounded-md px-1.5 py-1 hover:bg-surface-sunken">
                                <VCheckbox :model-value="visible.has(column.key)" @update:model-value="toggleColumn(column.key)">
                                    <Bilingual :k="column.labelKey" inline class="text-xs" />
                                </VCheckbox>
                            </label>
                        </div>
                    </VDropdown>
                    <template v-if="can.export">
                        <VButton variant="secondary" size="sm" icon="export" @click="exportAs('excel')">Excel</VButton>
                        <VButton variant="secondary" size="sm" icon="export" @click="exportAs('pdf')">PDF</VButton>
                    </template>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <VSelect v-model="filters.status" @update:model-value="apply()">
                    <option value="">{{ $tPair('employees.status') }}</option>
                    <option value="active">{{ $t('employees.active') }}</option>
                    <option value="inactive">{{ $t('employees.inactive') }}</option>
                    <option value="transferred">{{ $t('employees.status_transferred') }}</option>
                </VSelect>
                <VSelect v-model="filters.department" @update:model-value="apply()">
                    <option value="">{{ $t('employees.department') }}</option>
                    <option v-for="dept in filterOptions.departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                </VSelect>
                <VSelect v-model="filters.designation" @update:model-value="apply()">
                    <option value="">{{ $t('employees.designation') }}</option>
                    <option v-for="role in filterOptions.designations" :key="role" :value="role">{{ role }}</option>
                </VSelect>
                <VSelect v-model="filters.wage_type" @update:model-value="apply()">
                    <option value="">{{ $t('employees.wage_type') }}</option>
                    <option v-for="type in filterOptions.wageTypes" :key="type" :value="type">
                        {{ $t(`employees.wage_${type}`) }}
                    </option>
                </VSelect>
            </div>
        </div>

        <VBulkBar :count="selected.size" @clear="selected = new Set()">
            <template v-if="can.edit">
                <VButton variant="secondary" size="sm" @click="bulkActive(true)">
                    <Bilingual k="employees.bulk_activate" inline />
                </VButton>
                <VButton variant="secondary" size="sm" @click="bulkActive(false)">
                    <Bilingual k="employees.bulk_deactivate" inline />
                </VButton>
            </template>
        </VBulkBar>

        <VTable :columns="columns" :sort="{ key: filters.sort, dir: filters.dir }" selectable
            :all-selected="selected.size > 0 && selected.size === employees.data.length"
            @sort="sortBy" @toggle-all="toggleAll">
            <tr v-for="employee in employees.data" :key="employee.id"
                class="cursor-pointer hover:bg-surface-hover"
                @click="router.get(`/employees/${employee.id}`)">
                <td class="px-3 py-2.5" @click.stop>
                    <input type="checkbox" :checked="selected.has(employee.id)"
                        class="h-4 w-4 rounded-sm accent-[var(--color-accent)]"
                        @change="toggleRow(employee.id)" />
                </td>
                <template v-for="column in columns" :key="column.key">
                    <td v-if="column.key === 'full_name'" class="px-3 py-2.5">
                        <span class="flex items-center gap-2">
                            <VAvatar :name="employee.full_name" size="sm" />
                            <span class="text-sm font-medium">{{ employee.full_name }}</span>
                        </span>
                    </td>
                    <td v-else-if="column.key === 'active'" class="px-3 py-2.5">
                        <VBadge v-if="employee.status === 'transferred'" status="info">
                            <Bilingual k="employees.status_transferred" inline />
                        </VBadge>
                        <VBadge v-else :status="employee.active ? 'ok' : 'neutral'">
                            <Bilingual :k="employee.active ? 'employees.active' : 'employees.inactive'" inline />
                        </VBadge>
                    </td>
                    <td v-else-if="column.key === 'doc_status'" class="px-3 py-2.5 text-center">
                        <VStatusDot :status="docDot[employee.doc_status] ?? 'neutral'" />
                    </td>
                    <td v-else-if="['wage_rate', 'base_salary', 'commission_percent'].includes(column.key)"
                        class="tabular-nums px-3 py-2.5 text-end text-sm">
                        {{ employee[column.key] ?? '—' }}
                    </td>
                    <td v-else-if="column.key === 'wage_type'" class="px-3 py-2.5 text-sm text-ink-soft">
                        {{ employee.wage_type ? $t(`employees.wage_${employee.wage_type}`) : '—' }}
                    </td>
                    <td v-else class="px-3 py-2.5 text-sm text-ink-soft">{{ employee[column.key] ?? '—' }}</td>
                </template>
            </tr>
            <template v-if="employees.data.length === 0" #empty>
                <VEmptyState icon="employees" />
            </template>
        </VTable>

        <VPagination :page="employees.current_page" :pages="employees.last_page"
            :per-page="filters.per_page" :total="employees.total"
            @update:page="(page) => apply({ page })"
            @update:per-page="(perPage) => { filters.per_page = perPage; apply(); }" />

        <!-- Create modal -->
        <EmployeeFormModal :open="showForm" :can-see-wages="canSeeWages" @close="showForm = false" />

        <!-- Import modal -->
        <VModal :open="showImport" title-key="employees.import" size="sm" @close="showImport = false">
            <div class="space-y-3">
                <a href="/employees/template" class="inline-flex items-center gap-1.5 text-sm text-accent-hover hover:underline">
                    <AppIcon name="download" class="h-4 w-4" />
                    <Bilingual k="employees.template" inline />
                </a>
                <VFileDrop accept=".xlsx,.xls,.csv" :disabled="importing" @files="importFile" />
            </div>
        </VModal>
    </AppLayout>
</template>
