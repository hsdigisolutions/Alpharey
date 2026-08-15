<script setup>
/**
 * Standalone Production Tasks screen — every task across all of the company's
 * projects in one table, with filters, exports, and the same Log-work /
 * edit / delete actions as a project's Tareas tab. Internal tracking only
 * (quantities are never client billing).
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    tasks: { type: Array, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    project_id: props.filters.project_id ?? '',
    category: props.filters.category ?? '',
    status: props.filters.status ?? '',
});
function apply() { router.get('/tasks', { ...filters }, { preserveScroll: true, preserveState: true }); }
const exportUrl = (path) => `/tasks/${path}?` + new URLSearchParams(Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v != null))).toString();

const taskHealth = { ok: 'bg-status-ok', warn: 'bg-status-warn', danger: 'bg-status-danger' };
const statusVariant = { open: 'neutral', in_progress: 'info', done: 'ok' };

const columns = [
    { key: 'name', labelKey: 'production_tasks.name' },
    { key: 'project', labelKey: 'production_tasks.project' },
    { key: 'category', labelKey: 'production_tasks.category' },
    { key: 'planned', labelKey: 'production_tasks.planned', align: 'end' },
    { key: 'done', labelKey: 'production_tasks.done', align: 'end' },
    { key: 'progress', labelKey: 'production_tasks.progress' },
    { key: 'status', labelKey: 'production_tasks.status' },
    { key: 'actions', labelKey: 'common.actions', align: 'end' },
];

// ── Edit task ───────────────────────────────────────────────────────────────
const editOpen = ref(false);
const editing = ref(null);
const editForm = useForm({ name: '', category: 'other', house_number: '', unit: 'm²', unit_price: 0, planned_quantity: null, weightage: 0, status: 'open', notes: '' });
function openEdit(t) {
    editing.value = t;
    Object.assign(editForm, {
        name: t.name, category: t.category, house_number: t.house_number ?? '', unit: t.unit ?? 'm²',
        unit_price: t.unit_price ?? 0, planned_quantity: t.planned_quantity, weightage: t.weightage, status: t.status, notes: t.notes ?? '',
    });
    editForm.clearErrors();
    editOpen.value = true;
}
function submitEdit() {
    editForm.put(`/projects/${editing.value.project.id}/tasks/${editing.value.id}`, { preserveScroll: true, onSuccess: () => (editOpen.value = false) });
}

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }
function deleteTask(t) {
    askDelete(t.name, () => router.delete(`/projects/${t.project.id}/tasks/${t.id}`, { preserveScroll: true }));
}

// ── Log work (daily production entry) ───────────────────────────────────────
const logOpen = ref(false);
const logTask = ref(null);
const presentWorkers = ref([]);
const loadingWorkers = ref(false);
const logForm = useForm({ date: new Date().toISOString().slice(0, 10), quantity: null, employee_ids: [], notes: '', photo: null });

async function fetchPresentWorkers() {
    if (!logTask.value) return;
    loadingWorkers.value = true;
    logForm.employee_ids = [];
    try {
        const res = await fetch(`/projects/${logTask.value.project.id}/present-workers?date=${logForm.date}`, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        presentWorkers.value = data.workers ?? [];
    } catch { presentWorkers.value = []; }
    loadingWorkers.value = false;
}
function openLog(t) {
    logTask.value = t;
    logForm.date = new Date().toISOString().slice(0, 10);
    logForm.quantity = null;
    logForm.employee_ids = [];
    logForm.notes = '';
    logForm.photo = null;
    logForm.clearErrors();
    presentWorkers.value = [];
    logOpen.value = true;
    fetchPresentWorkers();
}
const splitPreview = computed(() => {
    const n = logForm.employee_ids.length;
    const q = Number(logForm.quantity);
    if (!n || !q) return null;
    return (q / n).toFixed(2);
});
function submitLog() {
    logForm.transform((d) => ({ ...d, quantity: d.quantity ?? '' }))
        .post(`/projects/${logTask.value.project.id}/tasks/${logTask.value.id}/progress`, {
            preserveScroll: true, forceFormData: true, onSuccess: () => (logOpen.value = false),
        });
}
</script>

<template>
    <Head :title="$t('production_tasks.title')" />
    <AppLayout>
        <VPageHeader k="production_tasks.title">
            <a :href="exportUrl('export')" class="inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-surface-raised px-3 py-2 text-sm text-ink hover:bg-surface-hover">
                <AppIcon name="download" class="h-4 w-4" /> Excel
            </a>
            <a :href="exportUrl('export-pdf')" class="inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-surface-raised px-3 py-2 text-sm text-ink hover:bg-surface-hover">
                <AppIcon name="download" class="h-4 w-4" /> PDF
            </a>
        </VPageHeader>

        <div class="flex flex-wrap items-end gap-2 pb-3">
            <VInput v-model="filters.search" class="w-full sm:w-56" :placeholder="$t('production_tasks.search')" @keyup.enter="apply()" />
            <VSelect v-model="filters.project_id" class="w-full sm:w-56" @update:model-value="apply()">
                <option value="">{{ $t('production_tasks.all_projects') }}</option>
                <option v-for="p in filterOptions.projects" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option>
            </VSelect>
            <VSelect v-model="filters.category" class="w-full sm:w-44" @update:model-value="apply()">
                <option value="">{{ $t('production_tasks.all_categories') }}</option>
                <option v-for="c in filterOptions.categories" :key="c" :value="c">{{ $t(`production_tasks.cat_${c}`) }}</option>
            </VSelect>
            <VSelect v-model="filters.status" class="w-full sm:w-40" @update:model-value="apply()">
                <option value="">{{ $t('production_tasks.all_statuses') }}</option>
                <option v-for="s in filterOptions.statuses" :key="s" :value="s">{{ $t(`production_tasks.st_${s}`) }}</option>
            </VSelect>
        </div>

        <VTable :columns="columns">
            <tr v-for="t in tasks" :key="t.id" class="hover:bg-surface-hover">
                <td class="px-3 py-2.5 text-sm font-medium">{{ t.name }}</td>
                <td class="px-3 py-2.5 text-sm">
                    <a v-if="t.project" :href="`/projects/${t.project.id}`" class="text-accent hover:underline">{{ t.project.code }}</a>
                    <span v-else>—</span>
                </td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ $t(`production_tasks.cat_${t.category}`) }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ t.planned_quantity }} {{ t.unit }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ t.completed_quantity }}</td>
                <td class="px-3 py-2.5">
                    <div class="flex items-center gap-2">
                        <div class="h-2 w-24 overflow-hidden rounded-full bg-surface-sunken">
                            <div class="h-full rounded-full" :class="taskHealth[t.health]" :style="{ width: t.progress + '%' }" />
                        </div>
                        <span class="tabular-nums text-xs">{{ t.progress }}%</span>
                    </div>
                </td>
                <td class="px-3 py-2.5"><VBadge :status="statusVariant[t.status]">{{ $t(`production_tasks.st_${t.status}`) }}</VBadge></td>
                <td class="px-3 py-2.5 text-end whitespace-nowrap">
                    <VButton v-if="can.edit" variant="ghost" size="sm" icon="attendance" :title="$t('task_progress.log')" @click="openLog(t)" />
                    <a v-if="t.project" :href="`/projects/${t.project.id}`" class="inline-flex" :title="$t('production_tasks.view_project')">
                        <VButton variant="ghost" size="sm" icon="eye" />
                    </a>
                    <VButton v-if="can.edit" variant="ghost" size="sm" icon="edit" @click="openEdit(t)" />
                    <VButton v-if="can.delete" variant="ghost" size="sm" icon="trash" @click="deleteTask(t)" />
                </td>
            </tr>
            <template v-if="tasks.length === 0" #empty><VEmptyState icon="columns" /></template>
        </VTable>
        <p class="pt-2 text-xs text-muted">{{ $t('production_tasks.internal_hint') }}</p>

        <!-- Log daily production -->
        <VModal :open="logOpen" title-key="task_progress.log" @close="logOpen = false">
            <div v-if="logTask" class="space-y-4">
                <p class="text-sm text-ink-soft">{{ logTask.name }} · {{ logTask.project?.code }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="task_progress.date" :error="logForm.errors.date" required>
                        <VInput v-model="logForm.date" type="date" @change="fetchPresentWorkers" />
                    </FormField>
                    <FormField k="task_progress.quantity" :error="logForm.errors.quantity" required>
                        <VInput v-model="logForm.quantity" type="number" step="0.01" min="0" :placeholder="logTask.unit" />
                    </FormField>
                </div>
                <div>
                    <p class="mb-1.5 text-sm font-medium"><Bilingual k="task_progress.workers" inline /></p>
                    <p class="mb-2 text-xs text-muted">{{ $t('task_progress.workers_hint') }}</p>
                    <div v-if="loadingWorkers" class="py-3 text-sm text-muted">{{ $t('common.loading') }}</div>
                    <div v-else-if="presentWorkers.length === 0" class="rounded-md bg-status-warn-soft px-3 py-2 text-sm text-status-warn">{{ $t('task_progress.none_present') }}</div>
                    <div v-else class="grid gap-1.5 sm:grid-cols-2">
                        <label v-for="w in presentWorkers" :key="w.id" class="flex items-center gap-2 rounded-md border border-line px-2.5 py-1.5 text-sm hover:bg-surface-hover">
                            <input type="checkbox" :value="w.id" v-model="logForm.employee_ids" class="accent-accent" />
                            <span>{{ w.name }}</span>
                            <span v-if="w.designation" class="text-xs text-muted">· {{ w.designation }}</span>
                        </label>
                    </div>
                    <p v-if="logForm.errors.employee_ids" class="mt-1 text-xs text-status-danger">{{ logForm.errors.employee_ids }}</p>
                    <p v-if="splitPreview" class="mt-2 text-xs text-ink-soft">
                        {{ $t('task_progress.split_preview', { qty: splitPreview, unit: logTask.unit ?? '', n: logForm.employee_ids.length }) }}
                    </p>
                </div>
                <FormField k="task_progress.photo" :error="logForm.errors.photo">
                    <input type="file" accept="image/*,.pdf" capture="environment" class="block w-full text-sm text-ink-soft file:mr-3 file:rounded-md file:border-0 file:bg-surface-sunken file:px-3 file:py-1.5 file:text-sm" @change="(e) => (logForm.photo = e.target.files[0] ?? null)" />
                </FormField>
                <FormField k="task_progress.notes" :error="logForm.errors.notes"><VTextarea v-model="logForm.notes" :rows="2" /></FormField>
            </div>
            <template #footer>
                <VButton variant="ghost" @click="logOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="button" :loading="logForm.processing" :disabled="!logForm.employee_ids.length" @click="submitLog"><Bilingual k="task_progress.save_btn" inline /></VButton>
            </template>
        </VModal>

        <!-- Edit task -->
        <VModal :open="editOpen" title-key="production_tasks.edit" @close="editOpen = false">
            <form id="task-edit-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitEdit">
                <FormField k="production_tasks.category" :error="editForm.errors.category" required>
                    <VSelect v-model="editForm.category"><option v-for="c in filterOptions.categories" :key="c" :value="c">{{ $t(`production_tasks.cat_${c}`) }}</option></VSelect>
                </FormField>
                <FormField k="production_tasks.unit" :error="editForm.errors.unit"><VInput v-model="editForm.unit" :placeholder="$t('production_tasks.ph_unit')" /></FormField>
                <FormField k="production_tasks.name" class="sm:col-span-2" :error="editForm.errors.name" required><VInput v-model="editForm.name" :placeholder="$t('production_tasks.ph_name')" /></FormField>
                <FormField k="production_tasks.unit_price" :error="editForm.errors.unit_price">
                    <div class="relative">
                        <VInput v-model="editForm.unit_price" type="number" step="0.01" min="0" class="pe-7" :placeholder="$t('production_tasks.ph_unit_price')" />
                        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-sm text-muted">€</span>
                    </div>
                    <p class="mt-1 text-xs text-muted">{{ $t('production_tasks.unit_price_hint') }}</p>
                </FormField>
                <FormField k="production_tasks.planned" :error="editForm.errors.planned_quantity" required><VInput v-model="editForm.planned_quantity" type="number" step="0.01" min="0" :placeholder="$t('production_tasks.ph_planned')" /></FormField>
                <FormField k="production_tasks.weightage" :error="editForm.errors.weightage">
                    <VInput v-model="editForm.weightage" type="number" step="0.01" min="0" max="100" :placeholder="$t('production_tasks.ph_weightage')" />
                    <p class="mt-1 text-xs text-muted">{{ $t('production_tasks.weightage_hint') }}</p>
                </FormField>
                <FormField k="production_tasks.house" :error="editForm.errors.house_number"><VInput v-model="editForm.house_number" :placeholder="$t('production_tasks.ph_house')" /></FormField>
                <FormField k="production_tasks.status" :error="editForm.errors.status" required>
                    <VSelect v-model="editForm.status"><option v-for="s in filterOptions.statuses" :key="s" :value="s">{{ $t(`production_tasks.st_${s}`) }}</option></VSelect>
                </FormField>
                <FormField k="production_tasks.notes" class="sm:col-span-2" :error="editForm.errors.notes"><VTextarea v-model="editForm.notes" :rows="2" /></FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="editOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="task-edit-form" :loading="editForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
