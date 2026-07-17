<script setup>
/**
 * Screen 23 — Inventory. Five views: items, the stock ledger, employee
 * issues, project assignments and categories.
 *
 * Stock counters are never edited here — every change is a movement, because
 * the ledger is the authority and the counters are its cached tail.
 */
import { reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VToggle from '@/Components/ui/VToggle.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, required: true },
    categories: { type: Array, required: true },
    itemTypes: { type: Array, required: true },
    movementTypes: { type: Array, required: true },
    employees: { type: Array, required: true },
    projects: { type: Array, required: true },
    movements: { type: Array, required: true },
    issues: { type: Array, required: true },
    assignments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const view = ref('items');
const tabs = [
    { key: 'items', labelKey: 'inventory.tab_items' },
    { key: 'movements', labelKey: 'inventory.tab_movements' },
    { key: 'issues', labelKey: 'inventory.tab_issues' },
    { key: 'assignments', labelKey: 'inventory.tab_assignments' },
    { key: 'categories', labelKey: 'inventory.tab_categories' },
];

const filters = reactive({
    search: props.filters.search ?? '',
    equipment_category_id: props.filters.equipment_category_id ?? '',
    item_type: props.filters.item_type ?? '',
    per_page: Number(props.filters.per_page ?? 25),
});
function apply(extra = {}) {
    router.get('/inventory', { ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}

/* Item */
const showItem = ref(false);
const editingItem = ref(null);
const itemBlank = {
    name: '', sku: '', equipment_category_id: '', item_type: 'tool', unit: 'pcs',
    minimum_stock: 0, active: true, notes: '', opening_stock: null,
};
const itemForm = useForm({ ...itemBlank });

function openItem(item = null) {
    editingItem.value = item;
    Object.keys(itemBlank).forEach((k) => { itemForm[k] = item?.[k] ?? itemBlank[k]; });
    itemForm.clearErrors();
    showItem.value = true;
}
function submitItem() {
    const payload = itemForm.transform((d) => ({
        ...d,
        equipment_category_id: d.equipment_category_id || null,
    }));
    const opts = { preserveScroll: true, onSuccess: () => (showItem.value = false) };
    editingItem.value
        ? payload.put(`/inventory/items/${editingItem.value.id}`, opts)
        : payload.post('/inventory/items', opts);
}

/* Movement */
const movingItem = ref(null);
const movementForm = useForm({ movement_type: 'stock_in', quantity: null, employee_id: '', project_id: '', notes: '' });
function openMovement(item) {
    movingItem.value = item;
    movementForm.reset();
    movementForm.clearErrors();
}
function submitMovement() {
    movementForm.transform((d) => ({
        ...d,
        employee_id: d.employee_id || null,
        project_id: d.project_id || null,
    })).post(`/inventory/items/${movingItem.value.id}/movements`, {
        preserveScroll: true,
        onSuccess: () => (movingItem.value = null),
    });
}

/* Issue */
const issuingItem = ref(null);
const issueForm = useForm({ employee_id: '', issued_quantity: null, issue_date: null, expected_return_date: null, notes: '' });
function openIssue(item) {
    issuingItem.value = item;
    issueForm.reset();
    issueForm.clearErrors();
}
function submitIssue() {
    issueForm.post(`/inventory/items/${issuingItem.value.id}/issue`, {
        preserveScroll: true,
        onSuccess: () => (issuingItem.value = null),
    });
}

/* Return */
const returningIssue = ref(null);
const returnForm = useForm({ returned_quantity: null });
function openReturn(issue) {
    returningIssue.value = issue;
    returnForm.returned_quantity = issue.outstanding;
    returnForm.clearErrors();
}
function submitReturn() {
    returnForm.post(`/inventory/issues/${returningIssue.value.id}/return`, {
        preserveScroll: true,
        onSuccess: () => (returningIssue.value = null),
    });
}

/* Project assignment */
const assigningItem = ref(null);
const assignForm = useForm({ project_id: '', quantity: 1, start_date: null, end_date: null, notes: '' });
function openAssign(item) {
    assigningItem.value = item;
    assignForm.reset();
    assignForm.clearErrors();
}
function submitAssign() {
    assignForm.post(`/inventory/items/${assigningItem.value.id}/assign`, {
        preserveScroll: true,
        onSuccess: () => (assigningItem.value = null),
    });
}

/* Category */
const showCategory = ref(false);
const categoryForm = useForm({ name: '', description: '', active: true });
function submitCategory() {
    categoryForm.post('/inventory/categories', {
        preserveScroll: true,
        onSuccess: () => { showCategory.value = false; categoryForm.reset(); },
    });
}

const issueTone = { open: 'warn', partially_returned: 'info', returned: 'ok' };

const itemColumns = [
    { key: 'name', labelKey: 'inventory.name' },
    { key: 'sku', labelKey: 'inventory.sku' },
    { key: 'category', labelKey: 'inventory.category' },
    { key: 'type', labelKey: 'inventory.item_type' },
    { key: 'total', labelKey: 'inventory.total_stock', align: 'end' },
    { key: 'available', labelKey: 'inventory.available_stock', align: 'end' },
    { key: 'issued', labelKey: 'inventory.issued_stock', align: 'end' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
const movementColumns = [
    { key: 'created_at', labelKey: 'inventory.created_at' },
    { key: 'item', labelKey: 'inventory.name' },
    { key: 'type', labelKey: 'inventory.movement_type' },
    { key: 'quantity', labelKey: 'inventory.quantity', align: 'end' },
    { key: 'balance', labelKey: 'inventory.balance_after', align: 'end' },
    { key: 'employee', labelKey: 'inventory.employee' },
    { key: 'project', labelKey: 'inventory.project' },
];
const issueColumns = [
    { key: 'employee', labelKey: 'inventory.employee' },
    { key: 'item', labelKey: 'inventory.name' },
    { key: 'issued', labelKey: 'inventory.issued_quantity', align: 'end' },
    { key: 'returned', labelKey: 'inventory.returned_quantity', align: 'end' },
    { key: 'outstanding', labelKey: 'inventory.outstanding', align: 'end' },
    { key: 'issue_date', labelKey: 'inventory.issue_date' },
    { key: 'expected', labelKey: 'inventory.expected_return_date' },
    { key: 'status', labelKey: 'inventory.status' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];
const assignmentColumns = [
    { key: 'item', labelKey: 'inventory.name' },
    { key: 'project', labelKey: 'inventory.project' },
    { key: 'quantity', labelKey: 'inventory.quantity', align: 'end' },
    { key: 'start', labelKey: 'inventory.start_date' },
    { key: 'end', labelKey: 'inventory.end_date' },
    { key: 'status', labelKey: 'inventory.status' },
];
const categoryColumns = [
    { key: 'name', labelKey: 'inventory.name' },
    { key: 'description', labelKey: 'inventory.description' },
    { key: 'count', labelKey: 'inventory.item_count', align: 'end' },
    { key: 'active', labelKey: 'inventory.active' },
];
</script>

<template>
    <Head :title="$t('inventory.title')" />
    <AppLayout>
        <VPageHeader k="inventory.title">
            <VButton v-if="can.create && view === 'categories'" variant="secondary" icon="plus"
                @click="showCategory = true">
                <Bilingual k="inventory.new_category" inline />
            </VButton>
            <VButton v-if="can.create" icon="plus" @click="openItem()">
                <Bilingual k="inventory.new_item" inline />
            </VButton>
        </VPageHeader>

        <VTabs v-model="view" :tabs="tabs" class="mb-3" />

        <!-- Items -->
        <template v-if="view === 'items'">
            <div class="flex flex-wrap items-end gap-2 pb-3">
                <VSearchInput v-model="filters.search" class="w-full sm:w-72" :placeholder="$t('inventory.search')"
                    @update:model-value="apply()" />
                <VSelect v-model="filters.equipment_category_id" class="w-full sm:w-52" @update:model-value="apply()">
                    <option value="">{{ $t('inventory.category') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </VSelect>
                <VSelect v-model="filters.item_type" class="w-full sm:w-44" @update:model-value="apply()">
                    <option value="">{{ $t('inventory.item_type') }}</option>
                    <option v-for="t in itemTypes" :key="t" :value="t">{{ $t(`inventory.type_${t}`) }}</option>
                </VSelect>
            </div>

            <VTable :columns="itemColumns">
                <tr v-for="i in items.data" :key="i.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm font-medium">
                        {{ i.name }}
                        <VBadge v-if="i.low_stock" status="warn" class="ms-1.5">
                            <Bilingual k="inventory.low_stock" inline />
                        </VBadge>
                    </td>
                    <td class="tabular-nums px-3 py-2.5 text-sm text-ink-soft">{{ i.sku }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ i.category ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">
                        <Bilingual :k="`inventory.type_${i.item_type}`" inline />
                    </td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ i.total_stock }} {{ i.unit }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm font-medium">{{ i.available_stock }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm text-ink-soft">{{ i.issued_stock }}</td>
                    <td class="px-3 py-2.5 text-end">
                        <span class="flex items-center justify-end gap-1.5">
                            <VButton v-if="can.edit" variant="ghost" size="sm" @click="openMovement(i)">
                                <Bilingual k="inventory.record_movement" inline />
                            </VButton>
                            <VButton v-if="can.edit" variant="ghost" size="sm" @click="openIssue(i)">
                                <Bilingual k="inventory.issue" inline />
                            </VButton>
                            <VButton v-if="can.edit" variant="ghost" size="sm" @click="openAssign(i)">
                                <Bilingual k="inventory.assign_to_project" inline />
                            </VButton>
                            <VButton v-if="can.edit" variant="ghost" size="sm" icon="edit" @click="openItem(i)" />
                        </span>
                    </td>
                </tr>
                <template v-if="items.data.length === 0" #empty><VEmptyState icon="inventory" /></template>
            </VTable>

            <VPagination :page="items.current_page" :pages="items.last_page" :per-page="filters.per_page"
                :total="items.total" @update:page="(p) => apply({ page: p })"
                @update:per-page="(pp) => { filters.per_page = pp; apply(); }" />
        </template>

        <!-- Stock ledger -->
        <template v-else-if="view === 'movements'">
            <VTable :columns="movementColumns">
                <tr v-for="m in movements" :key="m.id" class="hover:bg-surface-hover">
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ m.created_at }}</td>
                    <td class="px-3 py-2.5 text-sm">{{ m.item ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">
                        <Bilingual :k="`inventory.type_${m.movement_type}`" inline />
                    </td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ m.quantity }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm font-medium">{{ m.balance_after ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.employee ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ m.project ?? '—' }}</td>
                </tr>
                <template v-if="movements.length === 0" #empty><VEmptyState icon="inventory" /></template>
            </VTable>
        </template>

        <!-- Employee issues -->
        <template v-else-if="view === 'issues'">
            <VTable :columns="issueColumns">
                <tr v-for="i in issues" :key="i.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm">{{ i.employee ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ i.item ?? '—' }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ i.issued_quantity }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ i.returned_quantity }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm font-medium">{{ i.outstanding }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ i.issue_date }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">
                        {{ i.expected_return_date ?? '—' }}
                        <VBadge v-if="i.overdue" status="danger" class="ms-1.5">
                            <Bilingual k="inventory.overdue" inline />
                        </VBadge>
                    </td>
                    <td class="px-3 py-2.5">
                        <VBadge :status="issueTone[i.status]">
                            <Bilingual :k="`inventory.status_${i.status}`" inline />
                        </VBadge>
                    </td>
                    <td class="px-3 py-2.5 text-end">
                        <VButton v-if="can.edit && i.status !== 'returned'" variant="ghost" size="sm"
                            @click="openReturn(i)">
                            <Bilingual k="inventory.return" inline />
                        </VButton>
                    </td>
                </tr>
                <template v-if="issues.length === 0" #empty><VEmptyState icon="inventory" /></template>
            </VTable>
        </template>

        <!-- Project assignments -->
        <template v-else-if="view === 'assignments'">
            <VTable :columns="assignmentColumns">
                <tr v-for="a in assignments" :key="a.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm">{{ a.item ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm">{{ a.project ?? '—' }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ a.quantity }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ a.start_date }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-sm text-ink-soft">{{ a.end_date ?? '—' }}</td>
                    <td class="px-3 py-2.5">
                        <VBadge :status="a.status === 'active' ? 'info' : 'ok'">
                            <Bilingual :k="`inventory.status_${a.status}`" inline />
                        </VBadge>
                    </td>
                </tr>
                <template v-if="assignments.length === 0" #empty><VEmptyState icon="inventory" /></template>
            </VTable>
        </template>

        <!-- Categories -->
        <template v-else>
            <VTable :columns="categoryColumns">
                <tr v-for="c in categories" :key="c.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm font-medium">{{ c.name }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ c.description ?? '—' }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ c.item_count }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ c.active ? '✓' : '—' }}</td>
                </tr>
                <template v-if="categories.length === 0" #empty><VEmptyState icon="inventory" /></template>
            </VTable>
        </template>

        <!-- Item -->
        <VModal :open="showItem" :title-key="editingItem ? 'inventory.edit_item' : 'inventory.new_item'"
            @close="showItem = false">
            <form id="item-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitItem">
                <FormField k="inventory.name" :error="itemForm.errors.name" required>
                    <VInput v-model="itemForm.name" />
                </FormField>
                <FormField k="inventory.sku" :error="itemForm.errors.sku" required>
                    <VInput v-model="itemForm.sku" />
                </FormField>
                <FormField k="inventory.category" :error="itemForm.errors.equipment_category_id">
                    <VSelect v-model="itemForm.equipment_category_id">
                        <option value="">—</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="inventory.item_type" :error="itemForm.errors.item_type" required>
                    <VSelect v-model="itemForm.item_type">
                        <option v-for="t in itemTypes" :key="t" :value="t">{{ $t(`inventory.type_${t}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="inventory.unit" :error="itemForm.errors.unit" required>
                    <VInput v-model="itemForm.unit" placeholder="pcs, m, kg" />
                </FormField>
                <FormField k="inventory.minimum_stock" :error="itemForm.errors.minimum_stock">
                    <VInput v-model="itemForm.minimum_stock" type="number" step="0.01" min="0" />
                </FormField>
                <!-- Opening stock is only offered on create: afterwards, stock
                     moves through the ledger, never through this form. -->
                <FormField v-if="!editingItem" k="inventory.opening_stock" :error="itemForm.errors.opening_stock">
                    <VInput v-model="itemForm.opening_stock" type="number" step="0.01" min="0" />
                </FormField>
                <FormField k="inventory.active"><VToggle v-model="itemForm.active" /></FormField>
                <FormField k="inventory.notes" class="sm:col-span-2">
                    <VTextarea v-model="itemForm.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showItem = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="item-form" :loading="itemForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Movement -->
        <VModal :open="movingItem !== null" title-key="inventory.record_movement" @close="movingItem = null">
            <form id="movement-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitMovement">
                <FormField k="inventory.movement_type" :error="movementForm.errors.movement_type" required>
                    <VSelect v-model="movementForm.movement_type">
                        <option v-for="t in movementTypes" :key="t" :value="t">{{ $t(`inventory.type_${t}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="inventory.quantity" :error="movementForm.errors.quantity" required>
                    <VInput v-model="movementForm.quantity" type="number" step="0.01" />
                </FormField>
                <FormField k="inventory.employee" :error="movementForm.errors.employee_id">
                    <VSelect v-model="movementForm.employee_id">
                        <option value="">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="inventory.project" :error="movementForm.errors.project_id">
                    <VSelect v-model="movementForm.project_id">
                        <option value="">—</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="inventory.notes" class="sm:col-span-2">
                    <VTextarea v-model="movementForm.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="movingItem = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="movement-form" :loading="movementForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Issue -->
        <VModal :open="issuingItem !== null" title-key="inventory.issue" @close="issuingItem = null">
            <form id="issue-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitIssue">
                <FormField k="inventory.employee" :error="issueForm.errors.employee_id" required>
                    <VSelect v-model="issueForm.employee_id">
                        <option value="">—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="inventory.issued_quantity" :error="issueForm.errors.issued_quantity" required>
                    <VInput v-model="issueForm.issued_quantity" type="number" step="0.01" min="0.01" />
                </FormField>
                <FormField k="inventory.issue_date" :error="issueForm.errors.issue_date">
                    <VDateInput v-model="issueForm.issue_date" />
                </FormField>
                <FormField k="inventory.expected_return_date" :error="issueForm.errors.expected_return_date">
                    <VDateInput v-model="issueForm.expected_return_date" />
                </FormField>
                <FormField k="inventory.notes" class="sm:col-span-2">
                    <VTextarea v-model="issueForm.notes" :rows="2" />
                </FormField>
                <p v-if="issueForm.errors.quantity" class="text-sm text-status-danger sm:col-span-2">
                    {{ issueForm.errors.quantity }}
                </p>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="issuingItem = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="issue-form" :loading="issueForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Return -->
        <VModal :open="returningIssue !== null" title-key="inventory.return" size="sm" @close="returningIssue = null">
            <form id="return-form" class="grid gap-4" @submit.prevent="submitReturn">
                <FormField k="inventory.returned_quantity" :error="returnForm.errors.returned_quantity" required>
                    <VInput v-model="returnForm.returned_quantity" type="number" step="0.01" min="0.01" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="returningIssue = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="return-form" :loading="returnForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Project assignment -->
        <VModal :open="assigningItem !== null" title-key="inventory.assign_to_project" @close="assigningItem = null">
            <form id="assign-item-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitAssign">
                <FormField k="inventory.project" :error="assignForm.errors.project_id" required>
                    <VSelect v-model="assignForm.project_id">
                        <option value="">—</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
                <FormField k="inventory.quantity" :error="assignForm.errors.quantity" required>
                    <VInput v-model="assignForm.quantity" type="number" step="0.01" min="0.01" />
                </FormField>
                <FormField k="inventory.start_date" :error="assignForm.errors.start_date" required>
                    <VDateInput v-model="assignForm.start_date" />
                </FormField>
                <FormField k="inventory.end_date" :error="assignForm.errors.end_date">
                    <VDateInput v-model="assignForm.end_date" />
                </FormField>
                <FormField k="inventory.notes" class="sm:col-span-2">
                    <VTextarea v-model="assignForm.notes" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="assigningItem = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="assign-item-form" :loading="assignForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Category -->
        <VModal :open="showCategory" title-key="inventory.new_category" size="sm" @close="showCategory = false">
            <form id="category-form" class="grid gap-4" @submit.prevent="submitCategory">
                <FormField k="inventory.name" :error="categoryForm.errors.name" required>
                    <VInput v-model="categoryForm.name" />
                </FormField>
                <FormField k="inventory.description" :error="categoryForm.errors.description">
                    <VTextarea v-model="categoryForm.description" :rows="2" />
                </FormField>
                <FormField k="inventory.active"><VToggle v-model="categoryForm.active" /></FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showCategory = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="category-form" :loading="categoryForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
